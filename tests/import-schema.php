<?php

declare(strict_types=1);

// Inspect generated SQL against in-memory schemas. No kernel, environment or DB connection.
use App\Entity\Record;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;
use Doctrine\ORM\Mapping\Index;
use DoctrineMigrations\Version20260930120000;
use DoctrineMigrations\Version20260930140000;
use DoctrineMigrations\Version20261018160000;
use DoctrineMigrations\Version20261018170000;

require dirname(__DIR__).'/vendor/autoload.php';
foreach ([Version20260930120000::class, Version20260930140000::class, Version20261018160000::class, Version20261018170000::class] as $class) {
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

$before = new Schema();
foreach (['records', 'records_import'] as $name) {
    $table = $before->createTable($name);
    $table->addColumn('id', 'integer');
    $table->addColumn('datestamp', 'datetime_immutable');
    $table->setPrimaryKey(['id']);
    $table->addIndex(['datestamp', 'id'], 'idx_records_datestamp_id');
    foreach (Record::PUBLICATION_COLUMNS as $column) {
        $table->addColumn($column, 'boolean');
    }
}
$after = clone $before;
$indexMigration = (new ReflectionClass(Version20261018170000::class))->newInstanceWithoutConstructor();
$indexMigration->up($after);
$platform = new MariaDBPlatform();
$comparator = new Comparator($platform);
$sql = $platform->getAlterSchemaSQL($comparator->compareSchemas($before, $after));
check(count($sql) === 8, 'Both tables need indexes for all four sets.');
foreach ($sql as $statement) {
    check(str_starts_with($statement, 'CREATE INDEX idx_records_publish_'), 'Migration must only create publication indexes.');
    check(preg_match('/\b(DROP|DELETE|TRUNCATE|CHANGE|MODIFY)\b/i', $statement) === 0, 'Index migration must preserve existing data and columns.');
}
$entityIndexes = [];
foreach ((new ReflectionClass(Record::class))->getAttributes(Index::class) as $attribute) {
    $index = $attribute->newInstance();
    $entityIndexes[$index->name] = $index->columns;
}
foreach (Record::PUBLICATION_COLUMNS as $column) {
    $name = 'idx_records_'.$column.'_datestamp_id';
    $columns = [$column, 'datestamp', 'id'];
    foreach (['records', 'records_import'] as $table) {
        check($after->getTable($table)->getIndex($name)->getColumns() === $columns, 'Index must cover the set filter, date range and stable ordering after publication swaps.');
    }
    check($entityIndexes[$name] === $columns, 'ORM mapping must preserve the deployed indexes.');
}
$rerun = clone $after;
$indexMigration->up($rerun);
check($comparator->compareSchemas($after, $rerun)->isEmpty(), 'Existing indexes must not be duplicated.');
$partial = clone $after;
$partial->getTable('records_import')->dropIndex('idx_records_publish_vkc_datestamp_id');
$indexMigration->up($partial);
check($comparator->compareSchemas($after, $partial)->isEmpty(), 'Partially applied indexes must be recoverable.');
$indexMigration->down($after);
check($comparator->compareSchemas($before, $after)->isEmpty(), 'Rollback must remove only the new indexes.');

echo "PASS: four set indexes on both swap tables, matching ORM metadata, MariaDB SQL, partial application and rollback\n";
