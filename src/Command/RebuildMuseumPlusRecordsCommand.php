<?php

declare(strict_types=1);

namespace App\Command;

use App\Import\MuseumPlusImportState;
use App\Import\MuseumPlusRecordBuilder;
use App\Import\RawResponseArchive;
use App\Mapping\MuseumPlusExportParser;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:rebuild-museumplus-records',
    description: 'Rebuild mapping profiles and publication sets from saved MuseumPlus source XML without fetching.',
)]
final class RebuildMuseumPlusRecordsCommand extends Command
{
    // Read every saved Object: both old aliases and the revised *Txt export.
    // PublicationSelector decides membership from parsed source, not stored output.
    private const SOURCE_BATCH_SQL = <<<'SQL'
        SELECT id, museumplus_id, museumplus_xml, raw_response_id
        FROM %s
        WHERE id > ?
        ORDER BY id
        LIMIT 100
        SQL;

    public function __construct(
        private readonly MuseumPlusExportParser $parser,
        private readonly MuseumPlusRecordBuilder $recordBuilder,
        private readonly Connection $connection,
        #[Autowire('%env(MUSEUMPLUS_OAI_IDENTIFIER_PREFIX)%')]
        private readonly string $identifierPrefix,
        #[Autowire('%env(MUSEUMPLUS_DEFAULT_SET_SPEC)%')]
        private readonly string $defaultSetSpec,
        private readonly RawResponseArchive $rawArchive = new RawResponseArchive(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('replace', null, InputOption::VALUE_NONE, 'Replace ALL current records atomically with rebuilt source records and publication profiles. Keep records_import and the checkpoint.');
        $this->addOption('source-table', null, InputOption::VALUE_REQUIRED, 'Saved source table: records, or records_import for explicit recovery of staging data.', 'records');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $progress = new MuseumPlusImportState($this->connection);
        $progress->lock();

        try {
            $sourceTable = $input->getOption('source-table');
            if (!in_array($sourceTable, ['records', 'records_import'], true)) throw new RuntimeException('source-table must be records or records_import.');
            if (trim($this->identifierPrefix) === '' || trim($this->defaultSetSpec) === '') {
                throw new RuntimeException('The OAI identifier prefix and default set must not be empty.');
            }

            $io->title('Rebuild from saved MuseumPlus source XML');
            $io->note('No MuseumPlus requests. Uses verified raw response files where linked; older records use their saved Object XML.');
            $date = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $summary = $this->connection->transactional(function () use ($input, $io, $date, $sourceTable): array {
                if ((int) $this->connection->fetchOne('SELECT COUNT(*) FROM records') > 0 && !$input->getOption('replace')) {
                    throw new RuntimeException('records is not empty. Use --replace to rebuild all saved source records and publication profiles.');
                }

                if ($input->getOption('replace') && $sourceTable !== 'records') {
                    // DELETE is transactional; TRUNCATE would commit the deletion immediately.
                    $this->connection->executeStatement('DELETE FROM records');
                }

                $lastId = 0;
                $stored = 0;
                $published = 0;
                $invalidFragments = 0;
                $repairedFragments = 0;
                $mappingWarnings = 0;
                $sets = [];
                $cachedRawId = null;
                $rawRecords = [];
                while (($rows = $this->connection->fetchAllAssociative(sprintf(self::SOURCE_BATCH_SQL, $sourceTable), [$lastId])) !== []) {
                    foreach ($rows as $row) {
                        try {
                            $rawId = $row['raw_response_id'] ?? null;
                            if ($rawId !== null) {
                                if ($rawId !== $cachedRawId) {
                                    $rawRecords = [];
                                    foreach ($this->parser->parse($this->rawArchive->read($rawId), $rawId) as $sourceRecord) {
                                        if (isset($rawRecords[$sourceRecord->museumplusId])) throw new RuntimeException('Duplicate MuseumPlus ID in raw response.');
                                        $rawRecords[$sourceRecord->museumplusId] = $sourceRecord;
                                    }
                                    $cachedRawId = $rawId;
                                }
                                $parsed = isset($rawRecords[$row['museumplus_id']]) ? [$rawRecords[$row['museumplus_id']]] : [];
                            } else {
                                // Legacy imports have no byte-exact archive; preserve their source.
                                $parsed = $this->parser->parse('<ObjectList>'.$row['museumplus_xml'].'</ObjectList>');
                            }
                            if (count($parsed) !== 1 || $parsed[0]->museumplusId === '' || $parsed[0]->museumplusId !== (string) $row['museumplus_id']) {
                                throw new RuntimeException('Source XML must contain exactly one matching MuseumPlus ID.');
                            }

                            $record = $parsed[0];
                            foreach ($record->values->repairedFragments as $fragment) {
                                $io->note(sprintf('MuseumPlus %s, field %s: repaired text escaping; content retained. Original error: %s', $record->museumplusId, $fragment['field'], $fragment['error']));
                                ++$repairedFragments;
                            }
                            foreach ($record->values->invalidFragments as $fragment) {
                                $io->warning(sprintf('MuseumPlus %s, field %s: %s', $record->museumplusId, $fragment['field'], $fragment['error']));
                                ++$invalidFragments;
                            }

                            $data = $this->recordBuilder->build($record, $date->format('Y-m-d H:i:s'), $this->identifierPrefix, $this->defaultSetSpec, static function (array $warning) use ($io, $record, &$mappingWarnings): void {
                                $io->warning(sprintf('MuseumPlus %s (%s), field %s: %s', $record->museumplusId, $record->values->objectNumber, $warning['field'], $warning['message']));
                                ++$mappingWarnings;
                            });
                            $data['museumplus_xml'] = $row['museumplus_xml'];
                            if ($sourceTable === 'records') {
                                $this->connection->update('records', $data, ['id' => $row['id']]);
                            } else {
                                $this->connection->insert('records', $data);
                            }
                        } catch (\Throwable $error) {
                            throw new RuntimeException(sprintf('Failed rebuilding MuseumPlus %s (staging row %s): %s', $row['museumplus_id'], $row['id'], $error->getMessage()), 0, $error);
                        }

                        $lastId = (int) $row['id'];
                        ++$stored;
                        $isPublished = false;
                        foreach (\App\Entity\Record::PUBLICATION_COLUMNS as $set => $column) {
                            if ($data[$column]) {
                                $sets[$set] = ($sets[$set] ?? 0) + 1;
                                $isPublished = true;
                            }
                        }
                        $published += (int) $isPublished;
                    }

                    $io->writeln(sprintf('Rebuilt %d records; not committed yet.', $stored));
                }

                if ($published === 0) {
                    throw new RuntimeException('No publishable records found in '.$sourceTable.'. Check museum assignments in config/museum-groups.php. No changes were committed.');
                }

                return compact('stored', 'published', 'invalidFragments', 'repairedFragments', 'mappingWarnings', 'sets');
            });

            $io->success(sprintf('Rebuilt %d records; %d belong to a publication set. records_import and its checkpoint were not changed.', $summary['stored'], $summary['published']));
            $io->table(['Set', 'Records'], array_map(static fn (string $set, int $count): array => [$set, $count], array_keys($summary['sets']), array_values($summary['sets'])));
            if ($summary['repairedFragments'] > 0) {
                $io->note(sprintf('%d field fragments had text escaping repaired; their content was retained.', $summary['repairedFragments']));
            }
            if ($summary['invalidFragments'] > 0) {
                $io->warning(sprintf('%d invalid field fragments were omitted by the parser, as during a normal import. Review the warnings above.', $summary['invalidFragments']));
            }
            if ($summary['mappingWarnings'] > 0) {
                $io->warning(sprintf('%d mapping warnings. Review omitted identifiers, public locations or dates above.', $summary['mappingWarnings']));
            }

            return Command::SUCCESS;
        } finally {
            try {
                $progress->unlock();
            } catch (\Throwable) {
                // A lost database connection releases its lock when the session ends.
            }
        }
    }
}
