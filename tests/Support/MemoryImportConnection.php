<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use RuntimeException;

/** Strict in-memory database double. Any unhandled database call fails closed. */
final class MemoryImportConnection extends Connection
{
    public array $tables = ['records' => [], 'records_import' => [], 'museumplus_import_state' => [], 'museumplus_import_errors' => []];
    public bool $locked = false;
    public bool $failCheckpoint = false;
    public bool $failStateInsert = false;
    public bool $failErrorLog = false;
    public ?string $failInsertId = null;
    public ?string $failRename = null;
    public ?string $failTruncate = null;
    public int $renames = 0;
    public int $importScans = 0;
    public int $importTruncates = 0;
    public int $commits = 0;
    public array $statements = [];
    public ?\Closure $beforeRebuildInsert = null;
    public ?string $failStatement = null;
    private ?array $snapshot = null;

    public function __construct()
    {
        // Deliberately no driver, DSN, environment or parent connection setup.
    }

    protected function connect(): DriverConnection
    {
        throw new RuntimeException('Real database connections are forbidden in this test.');
    }

    public function beginTransaction(): void
    {
        if ($this->snapshot !== null) {
            throw new RuntimeException('Unexpected nested transaction.');
        }

        $this->snapshot = $this->tables;
    }

    public function commit(): void
    {
        $this->snapshot = null;
        ++$this->commits;
    }

    public function rollBack(): void
    {
        $this->tables = $this->snapshot ?? throw new RuntimeException('No transaction to roll back.');
        $this->snapshot = null;
    }

    public function fetchOne(string $query, array $params = [], array $types = []): mixed
    {
        if (str_starts_with($query, 'SELECT GET_LOCK(')) {
            if ($this->locked) {
                return 0;
            }

            $this->locked = true;

            return 1;
        }

        if (str_starts_with($query, 'SELECT RELEASE_LOCK(')) {
            $this->locked = false;

            return 1;
        }

        if (in_array($query, ['SELECT COUNT(*) FROM records_import', 'SELECT COUNT(*) FROM records', 'SELECT COUNT(*) FROM records_rebuild'], true)) {
            if ($query === 'SELECT COUNT(*) FROM records_import') {
                ++$this->importScans;
            }

            return count($this->tables[substr($query, strlen('SELECT COUNT(*) FROM '))]);
        }

        throw new RuntimeException('Unexpected fetchOne: '.$query);
    }

    public function fetchAssociative(string $query, array $params = [], array $types = []): array|false
    {
        if ($query === 'SELECT * FROM museumplus_import_state WHERE id = 1') {
            return $this->tables['museumplus_import_state'][1] ?? false;
        }

        if ($query === 'SELECT COUNT(*) AS stored, MIN(datestamp) AS first_date, MAX(datestamp) AS last_date FROM records_import') {
            ++$this->importScans;
            $dates = array_column($this->tables['records_import'], 'datestamp');

            return ['stored' => count($dates), 'first_date' => $dates === [] ? null : min($dates), 'last_date' => $dates === [] ? null : max($dates)];
        }

        throw new RuntimeException('Unexpected fetchAssociative: '.$query);
    }

    public function fetchAllAssociative(string $query, array $params = [], array $types = []): array
    {
        if (!str_starts_with($query, 'SELECT id, museumplus_id, museumplus_xml') || !preg_match('/FROM (records_import|records)\b/', $query, $table) || !str_contains($query, 'LIMIT 100')) {
            throw new RuntimeException('Unexpected source query: '.$query);
        }

        $rows = [];
        foreach ($this->tables[$table[1]] as $row) {
            if ($row['id'] <= $params[0]) {
                continue;
            }
            $rows[] = array_intersect_key($row, array_flip(['id', 'museumplus_id', 'museumplus_xml', 'raw_response_id'])) + ['raw_response_id'=>null];
        }
        usort($rows, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return array_slice($rows, 0, 100);
    }

    public function insert(string $table, array $data, array $types = []): int|string
    {
        if ($table === 'records_rebuild' && $this->beforeRebuildInsert !== null) ($this->beforeRebuildInsert)($this, $data);
        if (!array_key_exists($table, $this->tables)) {
            throw new RuntimeException('Missing table: '.$table);
        }

        if ($table === 'museumplus_import_state' && $this->failStateInsert) {
            throw new RuntimeException('Simulated checkpoint insert failure');
        }

        if (isset($data['museumplus_id']) && $data['museumplus_id'] === $this->failInsertId) {
            throw new RuntimeException('Simulated record insert failure');
        }

        if ($table === 'museumplus_import_errors') {
            if ($this->failErrorLog) {
                throw new RuntimeException('Simulated error log failure');
            }

            $data['id'] = count($this->tables[$table]) + 1;
        }

        $key = $data['oai_identifier'] ?? $data['id'];

        if (isset($this->tables[$table][$key])) {
            throw new RuntimeException('Duplicate key in '.$table);
        }

        $this->assertUniquePriref($table, $data);
        $this->tables[$table][$key] = $data;

        return 1;
    }

    public function update(string $table, array $data, array $criteria = [], array $types = []): int|string
    {
        if ($table === 'records') {
            foreach ($this->tables[$table] as $key => $row) {
                if (($row['id'] ?? null) === $criteria['id']) {
                    if (($row['museumplus_id'] ?? null) === $this->failInsertId) throw new RuntimeException('Simulated record update failure');
                    $this->assertUniquePriref($table, $data, (string) $key);
                    unset($this->tables[$table][$key]);
                    $this->tables[$table][$data['oai_identifier']] = array_replace($row, $data);

                    return 1;
                }
            }
            throw new RuntimeException('Missing source record.');
        }
        if ($table === 'museumplus_import_state' && $this->failCheckpoint) {
            throw new RuntimeException('Simulated checkpoint failure');
        }

        $key = $criteria['id'];

        if (!isset($this->tables[$table][$key])) {
            throw new RuntimeException('Missing state row.');
        }

        $this->tables[$table][$key] = array_replace($this->tables[$table][$key], $data);

        return 1;
    }

    private function assertUniquePriref(string $table, array $data, ?string $ignoreKey = null): void
    {
        if (!isset($data['axiell_priref'])) return;
        foreach ($this->tables[$table] as $key => $row) {
            if ((string) $key !== $ignoreKey && ($row['axiell_priref'] ?? null) === $data['axiell_priref']) {
                throw new RuntimeException('Duplicate Axiell priref.');
            }
        }
    }

    public function executeStatement(string $sql, array $params = [], array $types = []): int|string
    {
        $sql = preg_replace('/\s+/', ' ', trim($sql));
        $this->statements[] = $sql;
        if ($sql === $this->failStatement) throw new RuntimeException('Simulated SQL failure: '.$sql);

        if (preg_match('/^(CREATE|DROP|RENAME|TRUNCATE) TABLE /', $sql) && $this->snapshot !== null) {
            throw new RuntimeException('DDL must run outside a transaction.');
        }

        if ($sql === 'TRUNCATE TABLE records_import') {
            if ($this->failTruncate === 'before') {
                throw new RuntimeException('Simulated failure before truncate');
            }

            $this->tables['records_import'] = [];
            ++$this->importTruncates;

            if ($this->failTruncate === 'after') {
                throw new RuntimeException('Simulated lost response after truncate');
            }

            return 0;
        }

        if (preg_match('/^(?:DELETE FROM|TRUNCATE TABLE) (\w+)$/', $sql, $match)) {
            $count = count($this->tables[$match[1]]);
            $this->tables[$match[1]] = [];

            return $count;
        }

        if (preg_match('/^DROP TABLE (?:IF EXISTS )?(\w+)$/', $sql, $match)) {
            unset($this->tables[$match[1]]);

            return 0;
        }

        if ($sql === 'CREATE TABLE museumplus_import_state_next LIKE museumplus_import_state') {
            $this->tables['museumplus_import_state_next'] = [];

            return 0;
        }

        if ($sql === 'CREATE TABLE records_rebuild LIKE records') {
            if (isset($this->tables['records_rebuild'])) throw new RuntimeException('Rebuild table already exists.');
            $this->tables['records_rebuild'] = [];

            return 0;
        }

        if ($sql === 'INSERT INTO museumplus_import_state_next SELECT * FROM museumplus_import_state') {
            $this->tables['museumplus_import_state_next'] = $this->tables['museumplus_import_state'];

            return 1;
        }

        if (str_starts_with($sql, 'RENAME TABLE ')) {
            if ($this->failRename === 'before') {
                throw new RuntimeException('Simulated failure before atomic rename');
            }

            $tables = $this->tables;

            foreach (explode(', ', substr($sql, strlen('RENAME TABLE '))) as $pair) {
                [$from, $to] = explode(' TO ', $pair);

                if (!isset($tables[$from]) || isset($tables[$to])) {
                    throw new RuntimeException('Invalid table rename: '.$pair);
                }

                $tables[$to] = $tables[$from];
                unset($tables[$from]);
            }

            $this->tables = $tables;
            ++$this->renames;

            if ($this->failRename === 'after') {
                throw new RuntimeException('Simulated lost response after atomic rename');
            }

            return 0;
        }

        throw new RuntimeException('Unexpected SQL: '.$sql);
    }
}
