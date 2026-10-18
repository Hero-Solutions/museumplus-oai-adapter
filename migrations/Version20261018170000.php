<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018170000 extends AbstractMigration
{
    private const PUBLICATION_COLUMNS = ['publish_msk', 'publish_brugge', 'publish_muzee', 'publish_vkc'];

    public function getDescription(): string
    {
        return 'Index publication filters and harvest ordering for all four OAI sets';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        // Publishing swaps these tables, so both must retain the same indexes.
        foreach (['records', 'records_import'] as $name) {
            $table = $schema->getTable($name);
            foreach (self::PUBLICATION_COLUMNS as $column) {
                $index = 'idx_records_'.$column.'_datestamp_id';
                if (!$table->hasIndex($index)) {
                    $table->addIndex([$column, 'datestamp', 'id'], $index);
                }
            }
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['records', 'records_import'] as $name) {
            $table = $schema->getTable($name);
            foreach (self::PUBLICATION_COLUMNS as $column) {
                $index = 'idx_records_'.$column.'_datestamp_id';
                if ($table->hasIndex($index)) {
                    $table->dropIndex($index);
                }
            }
        }
    }
}
