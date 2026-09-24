<?php

declare(strict_types=1);

namespace App\Command;

use App\Axiell\AxiellRecordMapper;
use App\Axiell\AxiellXmlRenderer;
use App\Mapping\MuseumPlusExportParser;
use App\Mapping\MuseumPlusParsedRecord;
use App\Mapping\RecordValues;
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
    private const LIVE_TABLE = 'records';
    private const OLD_TABLE = 'records_old';
    private const MAX_FETCH_RETRIES = 3;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly MuseumPlusExportParser $parser,
        private readonly AxiellRecordMapper $mapper,
        private readonly AxiellXmlRenderer $renderer,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'MuseumPlus fetch size.', '1000')
            ->addOption('start-offset', null, InputOption::VALUE_REQUIRED, 'MuseumPlus offset to start from. A nonzero offset upserts directly into records; it does not resume a full import.', '0')
            ->addOption('max-records', null, InputOption::VALUE_REQUIRED, 'Fetch at most this many records and upsert them directly into records.')
            ->addOption('allow-empty-swap', null, InputOption::VALUE_NONE, 'Allow swapping an empty import table.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $batchSize = $this->positiveIntOption($input, 'batch-size');
        $startOffset = $this->nonNegativeIntOption($input, 'start-offset');
        $maxRecords = $this->nullablePositiveIntOption($input, 'max-records');
        $allowEmptySwap = (bool) $input->getOption('allow-empty-swap');
        $partialImport = $startOffset > 0 || $maxRecords !== null;

        $url = $this->env('MUSEUMPLUS_EXPORT_URL');
        $username = $this->env('MUSEUMPLUS_USERNAME');
        $password = $this->env('MUSEUMPLUS_PASSWORD');
        $searchFieldPath = $this->env('MUSEUMPLUS_SEARCH_FIELD_PATH');
        $searchOperand = $this->env('MUSEUMPLUS_SEARCH_OPERAND');
        $identifierPrefix = $this->env('MUSEUMPLUS_OAI_IDENTIFIER_PREFIX');
        $defaultSetSpec = $this->env('MUSEUMPLUS_DEFAULT_SET_SPEC');
        $timeout = $this->positiveIntEnv('MUSEUMPLUS_TIMEOUT_SECONDS');
        $datestamp = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $io->title('MuseumPlus import');

        if (!$partialImport) {
            $this->truncateImportTable();
        }

        $offset = $startOffset;
        $fetched = 0;
        $stored = 0;
        $skipped = 0;
        $invalidFragments = 0;

        while ($maxRecords === null || $fetched < $maxRecords) {
            $limit = $maxRecords === null ? $batchSize : min($batchSize, $maxRecords - $fetched);
            $io->writeln(sprintf('Fetching offset %d, limit %d.', $offset, $limit));

            $xml = $this->fetchBatch(
                url: $url,
                username: $username,
                password: $password,
                searchFieldPath: $searchFieldPath,
                searchOperand: $searchOperand,
                limit: $limit,
                offset: $offset,
                timeout: $timeout,
                io: $io,
            );

            $records = $this->parser->parse($xml);
            $count = count($records);

            if ($count === 0) {
                break;
            }

            $stats = $this->storeBatch(
                records: $records,
                datestamp: $datestamp,
                identifierPrefix: $identifierPrefix,
                defaultSetSpec: $defaultSetSpec,
                table: $partialImport ? self::LIVE_TABLE : self::IMPORT_TABLE,
                upsert: $partialImport,
            );
            $fetched += $count;
            $stored += $stats['stored'];
            $skipped += $stats['skipped'];
            $invalidFragments += $stats['invalidFragments'];

            $io->writeln(sprintf('Stored %d records from this batch.', $stats['stored']));

            if ($count < $limit) {
                break;
            }

            $offset += $limit;
        }

        if ($stored === 0 && !$partialImport && !$allowEmptySwap) {
            throw new RuntimeException('Import returned zero records. Refusing table swap.');
        }

        if (!$partialImport) {
            $this->swapTables();
        }

        $io->success(sprintf(
            'Fetched %d records. Stored %d records%s. Skipped %d records without MuseumPlus ID. Invalid fragments: %d.',
            $fetched,
            $stored,
            $partialImport ? ' directly into records' : ' and published them to records',
            $skipped,
            $invalidFragments,
        ));

        return Command::SUCCESS;
    }

    /**
     * @param list<MuseumPlusParsedRecord> $records
     *
     * @return array{stored: int, skipped: int, invalidFragments: int}
     */
    private function storeBatch(
        array $records,
        DateTimeImmutable $datestamp,
        string $identifierPrefix,
        string $defaultSetSpec,
        string $table,
        bool $upsert,
    ): array {
        $stored = 0;
        $skipped = 0;
        $invalidFragments = 0;
        $formattedDate = $datestamp->format('Y-m-d H:i:s');

        $this->connection->beginTransaction();

        try {
            foreach ($records as $record) {
                if ($record->museumplusId === '') {
                    ++$skipped;

                    continue;
                }

                $invalidFragments += count($record->values->invalidFragments);
                $oaiXml = $this->renderer->renderRecord($this->mapper->map($record->values));

                $data = [
                    'museumplus_id' => $record->museumplusId,
                    'oai_identifier' => $identifierPrefix.$record->museumplusId,
                    'set_spec' => $this->setSpec($record->values, $defaultSetSpec),
                    'datestamp' => $formattedDate,
                    'object_number' => $record->values->objectNumber,
                    'oai_xml' => $oaiXml,
                    'museumplus_xml' => $record->museumplusXml,
                    'created_at' => $formattedDate,
                ];

                if ($upsert) {
                    $this->upsert($table, $data, 'oai_identifier');
                } else {
                    $this->connection->insert($table, $data);
                }

                ++$stored;
            }

            $this->connection->commit();
        } catch (\Throwable $exception) {
            $this->connection->rollBack();

            throw $exception;
        }

        return [
            'stored' => $stored,
            'skipped' => $skipped,
            'invalidFragments' => $invalidFragments,
        ];
    }

    /**
     * @param array<string, string|null> $data
     */
    private function upsert(string $table, array $data, string $uniqueColumn): void
    {
        $columns = array_keys($data);
        $updateColumns = array_values(array_filter(
            $columns,
            static fn (string $column): bool => $column !== $uniqueColumn,
        ));

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
            $this->connection->quoteSingleIdentifier($table),
            implode(', ', array_map($this->connection->quoteSingleIdentifier(...), $columns)),
            implode(', ', array_fill(0, count($columns), '?')),
            implode(', ', array_map(
                fn (string $column): string => sprintf(
                    '%s = VALUES(%s)',
                    $this->connection->quoteSingleIdentifier($column),
                    $this->connection->quoteSingleIdentifier($column),
                ),
                $updateColumns,
            )),
        );

        $this->connection->executeStatement($sql, array_values($data));
    }

    private function fetchBatch(
        string $url,
        string $username,
        string $password,
        string $searchFieldPath,
        string $searchOperand,
        int $limit,
        int $offset,
        int $timeout,
        SymfonyStyle $io,
    ): string {
        for ($attempt = 1; ; ++$attempt) {
            $response = null;

            try {
                $response = $this->httpClient->request('POST', $url, [
                    'auth_basic' => [$username, $password],
                    'headers' => [
                        'Accept' => 'application/octet-stream',
                        'Content-Type' => 'application/xml',
                    ],
                    'body' => $this->searchXml($searchFieldPath, $searchOperand, $limit, $offset),
                    'timeout' => $timeout,
                ]);

                $statusCode = $response->getStatusCode();
                // Responses are lazy: a transport failure can occur while reading the body.
                $content = $response->getContent(false);

                break;
            } catch (TransportExceptionInterface $exception) {
                $response?->cancel();

                if ($attempt > self::MAX_FETCH_RETRIES) {
                    throw new RuntimeException(sprintf(
                        'MuseumPlus transport failed at offset %d after %d attempts. No records from this batch were stored.',
                        $offset,
                        $attempt,
                    ), 0, $exception);
                }

                $delay = 2 ** ($attempt - 1);
                $io->warning(sprintf(
                    'MuseumPlus transport failed at offset %d. Retrying the same batch in %d seconds (%d/%d).',
                    $offset,
                    $delay,
                    $attempt,
                    self::MAX_FETCH_RETRIES,
                ));
                sleep($delay);
            }
        }

        if ($statusCode >= 400) {
            throw new RuntimeException(sprintf('MuseumPlus returned HTTP %d at offset %d.', $statusCode, $offset));
        }

        if (trim($content) === '') {
            throw new RuntimeException(sprintf('MuseumPlus returned an empty response at offset %d.', $offset));
        }

        return $content;
    }

    private function searchXml(string $fieldPath, string $operand, int $limit, int $offset): string
    {
        return sprintf(
            <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<application xmlns="http://www.zetcom.com/ria/ws/module/search">
  <modules>
    <module name="Object">
      <search limit="%d" offset="%d">
        <expert>
          <equalsField fieldPath="%s" operand="%s"/>
        </expert>
      </search>
    </module>
  </modules>
</application>
XML,
            $limit,
            $offset,
            $this->xmlAttribute($fieldPath),
            $this->xmlAttribute($operand),
        );
    }

    private function truncateImportTable(): void
    {
        $this->connection->executeStatement('TRUNCATE TABLE '.self::IMPORT_TABLE);
    }

    private function swapTables(): void
    {
        $this->connection->executeStatement('DROP TABLE IF EXISTS '.self::OLD_TABLE);
        $this->connection->executeStatement(sprintf(
            'RENAME TABLE %s TO %s, %s TO %s, %s TO %s',
            self::LIVE_TABLE,
            self::OLD_TABLE,
            self::IMPORT_TABLE,
            self::LIVE_TABLE,
            self::OLD_TABLE,
            self::IMPORT_TABLE,
        ));
        $this->connection->executeStatement('TRUNCATE TABLE '.self::IMPORT_TABLE);
    }

    private function setSpec(RecordValues $record, string $defaultSetSpec): string
    {
        $institution = strtolower($this->first($record, 'Naam_bewaarinstelling::institution.name') ?? '');

        return match (true) {
            str_contains($institution, 'schone kunsten gent') => 'msk_gent',
            str_contains($institution, 'mu.zee'), str_contains($institution, 'oostende') => 'muzee',
            str_contains($institution, 'musea brugge'), str_contains($institution, 'brugge') => 'musea_brugge',
            default => $defaultSetSpec,
        };
    }

    private function first(RecordValues $record, string $path): ?string
    {
        $values = array_values(array_filter(
            array_map(static fn (string $value): string => trim($value), $record->valuesByPath[$path] ?? []),
            static fn (string $value): bool => $value !== '',
        ));

        return $values[0] ?? null;
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

    private function nonNegativeIntOption(InputInterface $input, string $name): int
    {
        $value = $this->requiredOption($input, $name);

        if (!ctype_digit($value)) {
            throw new RuntimeException(sprintf('Option --%s must be a non-negative integer.', $name));
        }

        return (int) $value;
    }

    private function nullablePositiveIntOption(InputInterface $input, string $name): ?int
    {
        $value = $input->getOption($name);

        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value) || !ctype_digit($value) || (int) $value < 1) {
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

    private function xmlAttribute(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
