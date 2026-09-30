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
    public bool $failErrorLog = false;
    public ?string $failInsertId = null;
    public ?string $failRename = null;
    public int $renames = 0;
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

        if ($query === 'SELECT COUNT(*) FROM records_import') {
            return count($this->tables['records_import']);
        }

        throw new RuntimeException('Unexpected fetchOne: '.$query);
    }

    public function fetchAssociative(string $query, array $params = [], array $types = []): array|false
    {
        if ($query === 'SELECT * FROM museumplus_import_state WHERE id = 1') {
            return $this->tables['museumplus_import_state'][1] ?? false;
        }

        if ($query === 'SELECT COUNT(*) AS stored, MIN(datestamp) AS first_date, MAX(datestamp) AS last_date FROM records_import') {
            $dates = array_column($this->tables['records_import'], 'datestamp');

            return ['stored' => count($dates), 'first_date' => $dates === [] ? null : min($dates), 'last_date' => $dates === [] ? null : max($dates)];
        }

        throw new RuntimeException('Unexpected fetchAssociative: '.$query);
    }

    public function insert(string $table, array $data, array $types = []): int|string
    {
        if (!array_key_exists($table, $this->tables)) {
            throw new RuntimeException('Missing table: '.$table);
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

        $this->tables[$table][$key] = $data;

        return 1;
    }

    public function update(string $table, array $data, array $criteria = [], array $types = []): int|string
    {
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

    public function quoteSingleIdentifier(string $identifier): string
    {
        return '`'.$identifier.'`';
    }

    public function executeStatement(string $sql, array $params = [], array $types = []): int|string
    {
        $sql = preg_replace('/\s+/', ' ', trim($sql));

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

        if (preg_match('/^INSERT INTO `records` \(([^)]+)\).*ON DUPLICATE KEY UPDATE/', $sql, $match)) {
            $columns = array_map(static fn (string $column): string => trim($column, '` '), explode(',', $match[1]));
            $data = array_combine($columns, $params);
            $this->tables['records'][$data['oai_identifier']] = $data;

            return 1;
        }

        throw new RuntimeException('Unexpected SQL: '.$sql);
    }
}
