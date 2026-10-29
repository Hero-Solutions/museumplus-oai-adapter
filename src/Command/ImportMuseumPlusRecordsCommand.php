<?php

declare(strict_types=1);

namespace App\Command;

use App\Import\MuseumPlusImportState;
use App\Import\MuseumPlusRecordBuilder;
use App\Import\MuseumPlusSearch;
use App\Import\RawResponseArchive;
use App\Mapping\InvalidMuseumPlusResponse;
use App\Mapping\MuseumPlusExportParser;
use App\Mapping\MuseumPlusParsedRecord;
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
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:import-museumplus-records',
    description: 'Fetch MuseumPlus records into records_import and swap them into records.',
)]
final class ImportMuseumPlusRecordsCommand extends Command
{
    private const IMPORT_TABLE = 'records_import';
    private const MAX_FETCH_RETRIES = 3;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly MuseumPlusExportParser $parser,
        private readonly MuseumPlusRecordBuilder $recordBuilder,
        private readonly Connection $connection,
        private readonly RawResponseArchive $rawArchive,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'MuseumPlus fetch size.', '1000')
            ->addOption('resume', null, InputOption::VALUE_NONE, 'Resume an existing full import; fail if none exists. Incomplete imports also resume automatically without this flag.')
            ->addOption('restart', null, InputOption::VALUE_NONE, 'Discard staging progress and start over from zero; keep published records and raw files.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $progress = new MuseumPlusImportState($this->connection);
        $progress->lock();

        try {
            return $this->import($input, $output, $progress);
        } finally {
            try {
                $progress->unlock();
            } catch (\Throwable) {
                // A lost database connection releases its lock when the session ends.
            }
        }
    }

    private function import(InputInterface $input, OutputInterface $output, MuseumPlusImportState $progress): int
    {
        $io = new SymfonyStyle($input, $output);
        $batchSize = $this->positiveIntOption($input, 'batch-size');
        $resume = (bool) $input->getOption('resume');
        $restart = (bool) $input->getOption('restart');
        if ($resume && $restart) {
            throw new RuntimeException('--resume and --restart cannot be combined.');
        }

        $url = $this->env('MUSEUMPLUS_EXPORT_URL');
        $username = $this->env('MUSEUMPLUS_USERNAME');
        $password = $this->env('MUSEUMPLUS_PASSWORD');
        $publicationIds = MuseumPlusSearch::parsePublicationIds($this->env('MUSEUMPLUS_WEBPUBLICATION_IDS'));
        $identifierPrefix = $this->env('MUSEUMPLUS_OAI_IDENTIFIER_PREFIX');
        $defaultSetSpec = $this->env('MUSEUMPLUS_DEFAULT_SET_SPEC');
        $timeout = $this->positiveIntEnv('MUSEUMPLUS_TIMEOUT_SECONDS');

        $io->title('MuseumPlus import');

        $sourceSettings = [$url, MuseumPlusSearch::FIELD_PATH, $publicationIds, $identifierPrefix, $defaultSetSpec];
        // Never publish a mix of old conversions and new profiles after a resume.
        $sourceSettings[] = $this->recordBuilder->mappingVersion();
        $sourceHash = hash('sha256', json_encode($sourceSettings, JSON_THROW_ON_ERROR));

        if ($restart) {
            $io->writeln('Clearing records_import with TRUNCATE before restarting. This can wait for existing database locks.');
        }
        $state = $progress->prepare($sourceHash, $resume, $restart);
        $this->pruneRawResponses($io);
        if ($state['status'] === 'completed') {
            $io->success('This import has already been published. No records were fetched or swapped.');

            return Command::SUCCESS;
        }
        $offset = $fetched = (int) $state['next_offset'];
        $stored = (int) $state['stored'];
        $skipped = (int) $state['skipped'];
        $invalidFragments = (int) $state['invalid_fragments'];
        $datestamp = new DateTimeImmutable($state['datestamp'], new DateTimeZone('UTC'));
        $io->note(sprintf('Full import checkpoint: offset %d, %d records already stored. Re-run with --resume after an interruption.', $offset, $stored));

        // Resume a run of invalid objects with a single request per position.
        $currentBatchSize = (int) ($state['consecutive_invalid'] ?? 0) > 0 ? 1 : $batchSize;
        $recoveryEnd = null;
        $io->note('Original response bodies are archived in '.$this->rawArchive->directory().'.');

        while ($state['status'] === 'fetching') {
            $limit = $currentBatchSize;
            if ($recoveryEnd !== null) {
                $limit = min($limit, $recoveryEnd - $offset);
            }
            $io->writeln(sprintf('Fetching offset %d, limit %d.', $offset, $limit));

            $invalidRecord = null;
            try {
                $records = $this->fetchBatch(
                    url: $url,
                    username: $username,
                    password: $password,
                    publicationIds: $publicationIds,
                    limit: $limit,
                    offset: $offset,
                    timeout: $timeout,
                    io: $io,
                    sourceHash: $sourceHash,
                    importDatestamp: $datestamp->format('Y-m-d H:i:s'),
                );
            } catch (InvalidMuseumPlusResponse $error) {
                if ($error->museumplusId() === null) {
                    throw $error;
                }

                if ($limit > 1) {
                    $recoveryEnd ??= $offset + $limit;
                    $currentBatchSize = max(1, intdiv($limit, 2));
                    $io->warning(sprintf('Invalid MuseumPlus XML at offset %d. Reducing batch size from %d to %d at the same offset.', $offset, $limit, $currentBatchSize));

                    continue;
                }

                if (preg_match_all('~<Object(?:\s[^>]*)?>~', $error->responseBody) !== 1) {
                    throw $error;
                }

                $records = [];
                $invalidRecord = $error;
            }

            // An invalid singleton consumes one source position, never EOF.
            $count = $invalidRecord === null ? count($records) : 1;

            if ($count > $limit) {
                throw new RuntimeException('MuseumPlus returned more records than requested. Refusing to advance the checkpoint.');
            }

            [$stats, $state] = $this->connection->transactional(function () use ($records, $datestamp, $identifierPrefix, $defaultSetSpec, $progress, $state, $count, $limit, $invalidRecord, $sourceHash, $offset): array {
                if ($invalidRecord !== null) {
                    $progress->logInvalidRecord($sourceHash, $datestamp->format('Y-m-d H:i:s'), $offset, $invalidRecord);
                    $stats = ['stored' => 0, 'skipped' => 1, 'invalidFragments' => 0];
                    $nextState = $progress->advance($state, 1, $stats, false, true);

                    return [$stats, $nextState];
                }

                $stats = $this->storeBatch(
                    records: $records,
                    datestamp: $datestamp,
                    identifierPrefix: $identifierPrefix,
                    defaultSetSpec: $defaultSetSpec,
                );
                $nextState = $progress->advance($state, $count, $stats, $count < $limit);

                return [$stats, $nextState];
            });
            $fetched += $count;
            $stored += $stats['stored'];
            $skipped += $stats['skipped'];
            $invalidFragments += $stats['invalidFragments'];
            foreach ($stats['mappingWarnings'] ?? [] as $warning) {
                $io->warning($warning);
            }

            foreach ($records as $record) {
                foreach ($record->values->repairedFragments as $fragment) {
                    $io->note(sprintf('MuseumPlus %s, field %s: repaired text escaping; content retained. Original error: %s', $record->museumplusId, $fragment['field'], $fragment['error']));
                }
            }

            if ($invalidRecord !== null) {
                $io->warning(sprintf('Skipped invalid object at offset %d (MuseumPlus ID %s) after an invalid XML response. Error and raw response saved in museumplus_import_errors. Continuing at offset %d with batch size 1.', $offset, $invalidRecord->museumplusId(), $offset + 1));
            } else {
                $io->writeln(sprintf('Stored %d records from this batch.', $stats['stored']));
            }

            if ($count < $limit) {
                break;
            }

            $offset += $limit;
            if ($invalidRecord !== null) {
                $currentBatchSize = 1;
                $recoveryEnd = null;
            } elseif ($recoveryEnd !== null && $offset >= $recoveryEnd) {
                $currentBatchSize = $batchSize;
                $recoveryEnd = null;
            } elseif ($recoveryEnd === null && $currentBatchSize < $batchSize) {
                // Grow only after a valid batch; stay small through damaged stretches.
                $currentBatchSize += min($currentBatchSize, $batchSize - $currentBatchSize);
            }
        }

        if ($stored === 0) {
            throw new RuntimeException('Import returned zero records. Refusing table swap.');
        }

        $progress->publish();
        $this->pruneRawResponses($io);

        $io->success(sprintf(
            'Processed %d source positions. Published %d records. Skipped %d records (missing ID or invalid XML). Invalid fragments: %d.',
            $fetched,
            $stored,
            $skipped,
            $invalidFragments,
        ));

        return Command::SUCCESS;
    }

    private function pruneRawResponses(SymfonyStyle $io): void
    {
        try {
            $ids = $this->connection->fetchFirstColumn(<<<'SQL'
                SELECT raw_response_id FROM records WHERE raw_response_id IS NOT NULL AND raw_response_id <> ''
                UNION
                SELECT raw_response_id FROM records_import WHERE raw_response_id IS NOT NULL AND raw_response_id <> ''
                SQL);
            $removed = $this->rawArchive->prune($ids);
            if ($removed > 0) $io->writeln(sprintf('Removed %d obsolete raw response files.', $removed));
        } catch (\Throwable $error) {
            // A cleanup failure must never undo publication or discard the checkpoint.
            $io->warning('Could not finish raw response cleanup: '.$error->getMessage());
        }
    }

    /**
     * @param list<MuseumPlusParsedRecord> $records
     *
     * @return array{stored: int, skipped: int, invalidFragments: int, mappingWarnings: list<string>}
     */
    private function storeBatch(
        array $records,
        DateTimeImmutable $datestamp,
        string $identifierPrefix,
        string $defaultSetSpec,
    ): array {
        $stored = 0;
        $skipped = 0;
        $invalidFragments = 0;
        $mappingWarnings = [];
        $formattedDate = $datestamp->format('Y-m-d H:i:s');

        foreach ($records as $record) {
            if ($record->museumplusId === '') {
                ++$skipped;

                continue;
            }

            $invalidFragments += count($record->values->invalidFragments);
            $data = $this->recordBuilder->build($record, $formattedDate, $identifierPrefix, $defaultSetSpec, static function (array $warning) use ($record, &$mappingWarnings): void {
                $mappingWarnings[] = sprintf('MuseumPlus %s (%s), field %s: %s', $record->museumplusId, $record->values->objectNumber, $warning['field'], $warning['message']);
            });

            $this->connection->insert(self::IMPORT_TABLE, $data);

            ++$stored;
        }

        return [
            'stored' => $stored,
            'skipped' => $skipped,
            'invalidFragments' => $invalidFragments,
            'mappingWarnings' => $mappingWarnings,
        ];
    }

    /**
     * @param list<string> $publicationIds
     * @return list<MuseumPlusParsedRecord>
     */
    private function fetchBatch(
        string $url,
        string $username,
        string $password,
        array $publicationIds,
        int $limit,
        int $offset,
        int $timeout,
        SymfonyStyle $io,
        string $sourceHash,
        string $importDatestamp,
    ): array {
        for ($attempt = 1; ; ++$attempt) {
            $exception = null;

            try {
                $entry = $this->rawArchive->capture($this->httpClient, $url, [
                    'auth_basic' => [$username, $password],
                    'headers' => [
                        'Accept' => 'application/octet-stream',
                        'Content-Type' => 'application/xml',
                        'Accept-Encoding' => 'identity',
                    ],
                    'body' => MuseumPlusSearch::xml($publicationIds, $limit, $offset),
                    'timeout' => $timeout,
                    'buffer' => false,
                    'max_redirects' => 0,
                ], ['source_hash'=>$sourceHash, 'import_datestamp'=>$importDatestamp, 'offset'=>$offset, 'limit'=>$limit]);

                $statusCode = $entry['http_status'];

                if ($statusCode !== 200) {
                    $failure = sprintf('MuseumPlus returned HTTP %d at offset %d', $statusCode, $offset);

                    if (!in_array($statusCode, [500, 502, 503, 504], true)) {
                        throw new RuntimeException($failure.'.');
                    }
                } else {
                    // Parse only the saved, verified copy. Storage failures never advance progress.
                    $content = $this->rawArchive->read($entry['id']);

                    if (trim($content) === '') {
                        throw new InvalidMuseumPlusResponse('MuseumPlus returned an empty response.');
                    }

                    // HTTP 200 can still contain truncated XML. Validate the entire
                    // batch before returning any records or advancing the checkpoint.
                    return $this->parser->parse($content, $entry['id']);
                }
            } catch (TransportExceptionInterface $exception) {
                $failure = sprintf('MuseumPlus transport failed at offset %d', $offset);
            } catch (InvalidMuseumPlusResponse $exception) {
                // Invalid XML is isolated or skipped by the import loop, without retries.
                throw new InvalidMuseumPlusResponse(
                    sprintf('MuseumPlus returned an invalid response at offset %d: %s', $offset, $exception->getMessage()),
                    $exception->responseBody,
                    $exception,
                );
            }

            if ($attempt > self::MAX_FETCH_RETRIES) {
                $message = sprintf(
                    '%s after %d attempts. No records from this batch were stored.',
                    $failure,
                    $attempt,
                );
                throw new RuntimeException($message, 0, $exception);
            }

            $delay = 2 ** ($attempt - 1);
            $io->warning(sprintf(
                '%s. Retrying the same batch in %d second(s) (%d/%d).',
                $failure,
                $delay,
                $attempt,
                self::MAX_FETCH_RETRIES,
            ));
            sleep($delay);
        }
    }

    private function env(string $name, ?string $default = null): string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        if (!is_string($value) || trim($value) === '') {
            if ($default !== null) {
                return $default;
            }

            throw new RuntimeException(sprintf('Missing required env var %s.', $name));
        }

        return $value;
    }

    private function positiveIntEnv(string $name): int
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException(sprintf('Missing required env var %s.', $name));
        }

        if (!ctype_digit($value) || (int) $value < 1) {
            throw new RuntimeException(sprintf('Env var %s must be a positive integer.', $name));
        }

        return (int) $value;
    }

    private function positiveIntOption(InputInterface $input, string $name): int
    {
        $value = $this->requiredOption($input, $name);

        if (!ctype_digit($value) || (int) $value < 1) {
            throw new RuntimeException(sprintf('Option --%s must be a positive integer.', $name));
        }

        return (int) $value;
    }

    private function requiredOption(InputInterface $input, string $name): string
    {
        $value = $input->getOption($name);

        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException(sprintf('Missing required option --%s.', $name));
        }

        return $value;
    }
}
