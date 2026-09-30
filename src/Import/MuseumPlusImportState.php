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
    public function prepare(string $sourceHash, bool $resume, bool $restart, int $startOffset): array
    {
        $state = $this->connection->fetchAssociative('SELECT * FROM '.self::TABLE.' WHERE id = 1');

        if ($state !== false && !$restart && ($state['status'] !== 'completed' || $resume)) {
            if (!in_array($state['status'], ['fetching', 'ready', 'completed'], true)) {
                throw new RuntimeException('Unknown import checkpoint status. Refusing to fetch or publish.');
            }

            if ($state['source_hash'] !== $sourceHash) {
                throw new RuntimeException('Import settings changed. Restore the original source/filter settings or explicitly use --restart.');
            }

            if ($startOffset > 0 && $startOffset !== (int) $state['next_offset']) {
                throw new RuntimeException(sprintf('Saved next offset is %d; omit --start-offset when resuming.', $state['next_offset']));
            }

            if ($state['status'] !== 'completed' && (int) $this->connection->fetchOne('SELECT COUNT(*) FROM records_import') !== (int) $state['stored']) {
                throw new RuntimeException('records_import does not match the saved checkpoint. Refusing to skip records or publish an incomplete import.');
            }

            return $state;
        }

        $summary = $this->connection->fetchAssociative('SELECT COUNT(*) AS stored, MIN(datestamp) AS first_date, MAX(datestamp) AS last_date FROM records_import');
        $stored = (int) $summary['stored'];
        $datestamp = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        if ($resume) {
            // Older runs have no checkpoint. The operator supplies the failed fetch offset.
            if ($startOffset === 0 || $stored === 0 || $stored > $startOffset || $summary['first_date'] !== $summary['last_date']) {
                throw new RuntimeException('No saved checkpoint. Recover an existing records_import with --resume --start-offset=<failed fetch offset>; it must contain batches from one full import.');
            }

            $datestamp = $summary['first_date'];
        } elseif (!$restart && $state === false && $stored > 0) {
            throw new RuntimeException('records_import already contains data without a checkpoint. Use --resume --start-offset=<failed fetch offset> to keep it, or --restart to discard it.');
        }

        $newState = [
            'id' => 1,
            'source_hash' => $sourceHash,
            'next_offset' => $resume ? $startOffset : 0,
            'datestamp' => $datestamp,
            'stored' => $resume ? $stored : 0,
            'skipped' => $resume ? $startOffset - $stored : 0,
            'invalid_fragments' => 0,
            'consecutive_invalid' => 0,
            'status' => 'fetching',
        ];

        $this->connection->transactional(function () use ($resume, $newState): void {
            // DELETE keeps clearing the staging table and resetting the checkpoint atomic.
            if (!$resume) {
                $this->connection->executeStatement('DELETE FROM records_import');
            }

            $this->connection->executeStatement('DELETE FROM '.self::TABLE);
            $this->connection->insert(self::TABLE, $newState);
        });

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

    /**
     * @param array<string, int|string> $state
     * @return array<string, int|string>
     */
    public function skipOffset(array $state, int $offset): array
    {
        if ($state['status'] !== 'fetching') {
            throw new RuntimeException('Cannot skip an offset: this import is no longer fetching records.');
        }

        if ($offset !== (int) $state['next_offset']) {
            throw new RuntimeException(sprintf('Saved next offset is %d; refusing to skip offset %d. If it was already skipped, resume without --skip-offset.', $state['next_offset'], $offset));
        }

        if ($offset === PHP_INT_MAX) {
            throw new RuntimeException('Cannot advance beyond the maximum supported offset.');
        }

        // Commit before the next request, so a later failure cannot undo this
        // deliberate skip. Reusing the old --skip-offset then fails the equality check.
        return $this->connection->transactional(fn (): array => $this->advance(
            $state,
            1,
            ['stored' => 0, 'skipped' => 1, 'invalidFragments' => 0],
            false,
        ));
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
