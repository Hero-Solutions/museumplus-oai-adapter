<?php

declare(strict_types=1);

namespace App\Import;

use App\Mapping\InvalidMuseumPlusResponse;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use RuntimeException;

final class MuseumPlusImportState
{
    public const TABLE = 'museumplus_import_state';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function lock(): void
    {
        if ((int) $this->connection->fetchOne("SELECT GET_LOCK(CONCAT('museumplus-import:', LEFT(SHA2(DATABASE(), 256), 32)), 0)") !== 1) {
            throw new RuntimeException('Another MuseumPlus import is already running.');
        }
    }

    public function unlock(): void
    {
        $this->connection->fetchOne("SELECT RELEASE_LOCK(CONCAT('museumplus-import:', LEFT(SHA2(DATABASE(), 256), 32)))");
    }

    /** @return array<string, int|string> */
    public function prepare(string $sourceHash, bool $resume, bool $restart): array
    {
        $state = $this->connection->fetchAssociative('SELECT * FROM '.self::TABLE.' WHERE id = 1');

        if ($state !== false && !$restart && ($state['status'] !== 'completed' || $resume)) {
            if ($state['status'] === 'resetting') {
                throw new RuntimeException('Import reset was interrupted. Run again with --restart to finish clearing records_import and start from zero.');
            }

            if (!in_array($state['status'], ['fetching', 'ready', 'completed'], true)) {
                throw new RuntimeException('Unknown import checkpoint status. Refusing to fetch or publish.');
            }

            if ($state['source_hash'] !== $sourceHash) {
                throw new RuntimeException('Import settings changed. Restore the original source/filter settings or explicitly use --restart.');
            }

            if ($state['status'] !== 'completed' && (int) $this->connection->fetchOne('SELECT COUNT(*) FROM records_import') !== (int) $state['stored']) {
                throw new RuntimeException('records_import does not match the saved checkpoint. Refusing to skip records or publish an incomplete import.');
            }

            return $state;
        }

        $datestamp = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        if ($resume) {
            // Without the checkpoint we cannot establish the mapping/profile version.
            throw new RuntimeException('No saved checkpoint. The saved mapping version cannot be verified. Rebuild saved XML separately, or explicitly restart the import. records_import was preserved.');
        } elseif (!$restart && $state === false && (int) $this->connection->fetchOne('SELECT COUNT(*) FROM records_import') > 0) {
            throw new RuntimeException('records_import already contains data without a checkpoint. Rebuild saved XML separately, or explicitly use --restart to discard staging and fetch again.');
        }

        $newState = [
            'id' => 1,
            'source_hash' => $sourceHash,
            'next_offset' => 0,
            'datestamp' => $datestamp,
            'stored' => 0,
            'skipped' => 0,
            'invalid_fragments' => 0,
            'consecutive_invalid' => 0,
            'status' => 'resetting',
        ];

        // Commit the reset marker before TRUNCATE, which implicitly commits.
        // An interruption must never leave an old checkpoint usable with an empty table.
        $this->connection->transactional(function () use ($newState): void {
            $this->connection->executeStatement('DELETE FROM '.self::TABLE);
            $this->connection->insert(self::TABLE, $newState);
        });

        $this->connection->executeStatement('TRUNCATE TABLE records_import');
        $newState['status'] = 'fetching';
        $this->connection->update(self::TABLE, ['status' => 'fetching'], ['id' => 1]);

        return $newState;
    }

    /**
     * Called inside the same transaction as the records in this batch.
     *
     * @param array<string, int|string> $state
     * @param array{stored: int, skipped: int, invalidFragments: int} $stats
     * @return array<string, int|string>
     */
    public function advance(array $state, int $count, array $stats, bool $finished, bool $invalidRecord = false): array
    {
        $state['next_offset'] = (int) $state['next_offset'] + $count;
        $state['stored'] = (int) $state['stored'] + $stats['stored'];
        $state['skipped'] = (int) $state['skipped'] + $stats['skipped'];
        $state['invalid_fragments'] = (int) $state['invalid_fragments'] + $stats['invalidFragments'];
        $state['consecutive_invalid'] = $invalidRecord ? (int) ($state['consecutive_invalid'] ?? 0) + 1 : 0;
        $state['status'] = $finished ? 'ready' : 'fetching';
        $this->connection->update(self::TABLE, $state, ['id' => 1]);

        return $state;
    }

    /** Called inside the same transaction as the skip checkpoint. */
    public function logInvalidRecord(string $sourceHash, string $datestamp, int $offset, InvalidMuseumPlusResponse $error): void
    {
        $this->connection->insert('museumplus_import_errors', [
            'source_hash' => $sourceHash,
            'import_datestamp' => $datestamp,
            'source_offset' => $offset,
            'museumplus_id' => $error->museumplusId(),
            'error_message' => $error->getMessage(),
            'response_body' => $error->responseBody,
            'created_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
        ], ['response_body' => ParameterType::BINARY]);
    }

    public function publish(): void
    {
        // Publish the completion marker in the SAME rename as the records. A lost
        // connection after RENAME must never cause a resumed run to swap them back.
        $this->connection->executeStatement('DROP TABLE IF EXISTS museumplus_import_state_next');
        $this->connection->executeStatement('DROP TABLE IF EXISTS museumplus_import_state_old');
        $this->connection->executeStatement('CREATE TABLE museumplus_import_state_next LIKE '.self::TABLE);
        $this->connection->executeStatement('INSERT INTO museumplus_import_state_next SELECT * FROM '.self::TABLE);
        $this->connection->update('museumplus_import_state_next', ['status' => 'completed'], ['id' => 1]);
        $this->connection->executeStatement('DROP TABLE IF EXISTS records_old');
        $this->connection->executeStatement(<<<'SQL'
            RENAME TABLE records TO records_old,
                records_import TO records,
                records_old TO records_import,
                museumplus_import_state TO museumplus_import_state_old,
                museumplus_import_state_next TO museumplus_import_state
            SQL);
        $this->connection->executeStatement('TRUNCATE TABLE records_import');
        $this->connection->executeStatement('DROP TABLE museumplus_import_state_old');
    }
}
