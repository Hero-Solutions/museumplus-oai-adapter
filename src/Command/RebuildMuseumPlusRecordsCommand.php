<?php

declare(strict_types=1);

namespace App\Command;

use App\Import\MuseumPlusImportState;
use App\Import\MuseumPlusRecordBuilder;
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
    description: 'Rebuild Musea Brugge, Mu.ZEE and MSK Gent records from saved MuseumPlus source XML without fetching.',
)]
final class RebuildMuseumPlusRecordsCommand extends Command
{
    // Select by source institution, not the old set_spec or converted oai_xml.
    private const SOURCE_BATCH_SQL = <<<'SQL'
        SELECT id, museumplus_id, museumplus_xml
        FROM records_import
        WHERE id > ?
          AND museumplus_xml LIKE '%<Naam_bewaarinstelling>%'
          AND TRIM(REGEXP_REPLACE(
              REPLACE(REPLACE(
                  SUBSTRING_INDEX(
                      SUBSTRING_INDEX(museumplus_xml, '</Naam_bewaarinstelling>', 1),
                      '<Naam_bewaarinstelling>', -1
                  ), '&lt;', '<'), '&gt;', '>'),
              '<[^>]*>', ''
          )) IN (
              'Musea Brugge', 'Musea Brugge - Groeningemuseum',
              'Mu.ZEE, Kunstmuseum aan zee', 'Museum voor Schone Kunsten Gent'
          )
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
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('replace', null, InputOption::VALUE_NONE, 'Replace ALL current records atomically with the rebuilt museum selection. Keep records_import and the checkpoint.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $progress = new MuseumPlusImportState($this->connection);
        $progress->lock();

        try {
            if (trim($this->identifierPrefix) === '' || trim($this->defaultSetSpec) === '') {
                throw new RuntimeException('The OAI identifier prefix and default set must not be empty.');
            }

            $io->title('Rebuild from saved MuseumPlus source XML');
            $io->note('No MuseumPlus requests. Uses the current parser and mapping on previously fetched source XML.');
            $date = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $summary = $this->connection->transactional(function () use ($input, $io, $date): array {
                if ((int) $this->connection->fetchOne('SELECT COUNT(*) FROM records') > 0 && !$input->getOption('replace')) {
                    throw new RuntimeException('records is not empty. Use --replace only to rebuild the entire test selection from scratch.');
                }

                if ($input->getOption('replace')) {
                    // DELETE is transactional; TRUNCATE would commit the deletion immediately.
                    $this->connection->executeStatement('DELETE FROM records');
                }

                $lastId = 0;
                $stored = 0;
                $invalidFragments = 0;
                $repairedFragments = 0;
                $mappingWarnings = 0;
                $sets = [];
                while (($rows = $this->connection->fetchAllAssociative(self::SOURCE_BATCH_SQL, [$lastId])) !== []) {
                    foreach ($rows as $row) {
                        try {
                            // Stored source XML contains one Object, without the export's ObjectList wrapper.
                            $parsed = $this->parser->parse('<ObjectList>'.$row['museumplus_xml'].'</ObjectList>');
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
                            $this->connection->insert('records', $data);
                        } catch (\Throwable $error) {
                            throw new RuntimeException(sprintf('Failed rebuilding MuseumPlus %s (staging row %s): %s', $row['museumplus_id'], $row['id'], $error->getMessage()), 0, $error);
                        }

                        $lastId = (int) $row['id'];
                        ++$stored;
                        $sets[$data['set_spec']] = ($sets[$data['set_spec']] ?? 0) + 1;
                    }

                    $io->writeln(sprintf('Rebuilt %d records; not committed yet.', $stored));
                }

                if ($stored === 0) {
                    throw new RuntimeException('No matching museum records found in records_import. No changes were committed.');
                }

                return compact('stored', 'invalidFragments', 'repairedFragments', 'mappingWarnings', 'sets');
            });

            $io->success(sprintf('Rebuilt and published %d records. records_import and its checkpoint were not changed.', $summary['stored']));
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
