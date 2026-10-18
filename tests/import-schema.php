<?php

declare(strict_types=1);

// Inspect generated SQL against in-memory schemas. No kernel, environment or DB connection.
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;
use DoctrineMigrations\Version20260930120000;
use DoctrineMigrations\Version20260930140000;
use DoctrineMigrations\Version20261018160000;

require dirname(__DIR__).'/vendor/autoload.php';
foreach ([Version20260930120000::class, Version20260930140000::class, Version20261018160000::class] as $class) {
    require dirname(__DIR__).'/migrations/'.substr($class, strrpos($class, '\\') + 1).'.php';
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return list<string> */
function plannedSql(string $class, Schema $schema): array
{
    // These migrations only enqueue SQL. Bypass the constructor to prevent DB setup.
    /** @var AbstractMigration $migration */
    $migration = (new ReflectionClass($class))->newInstanceWithoutConstructor();
    $migration->up($schema);

    return array_map(static fn ($query): string => preg_replace('/\s+/', ' ', trim($query->getStatement())), $migration->getSql());
}

$originalState = plannedSql(Version20260930120000::class, new Schema())[0];
$originalErrors = plannedSql(Version20260930140000::class, new Schema());
$expectedState = str_replace('PRIMARY KEY(id)', 'consecutive_invalid INT NOT NULL DEFAULT 0, PRIMARY KEY(id)', $originalState);

foreach ([false, true] as $hasState) {
    foreach ([false, true] as $hasErrors) {
        $schema = new Schema();
        // These tables represent existing published and staged records and migration history.
        foreach (['records', 'records_import', 'doctrine_migration_versions'] as $table) {
            $schema->createTable($table);
        }
        if ($hasState) {
            $schema->createTable('museumplus_import_state')->addColumn('consecutive_invalid', 'integer');
        }
        if ($hasErrors) {
            $schema->createTable('museumplus_import_errors');
        }

        $expected = [];
        if (!$hasState) {
            $expected[] = $expectedState;
        }
        if (!$hasErrors) {
            $expected[] = $originalErrors[1];
        }
        check(plannedSql(Version20261018160000::class, $schema) === $expected, 'Repair must only create missing tables, matching the original schema.');
    }
}

$olderSchema = new Schema();
$olderSchema->createTable('museumplus_import_state');
$olderSchema->createTable('museumplus_import_errors');
check(plannedSql(Version20261018160000::class, $olderSchema) === [$originalErrors[0]], 'Older checkpoints must only gain the missing counter.');

$migration = (new ReflectionClass(Version20261018160000::class))->newInstanceWithoutConstructor();
try {
    $migration->down(new Schema());
    throw new RuntimeException('Rollback must refuse to delete restored data.');
} catch (IrreversibleMigration) {
    check($migration->getSql() === [], 'Rollback must not enqueue destructive SQL.');
}

echo "PASS: missing, existing and older import schemas; original SQL compatibility; non-destructive rollback\n";
