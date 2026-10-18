<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Restore missing import checkpoint and error tables without replacing existing data';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('museumplus_import_state')) {
            $this->addSql(<<<'SQL'
                CREATE TABLE museumplus_import_state (
                    id INT NOT NULL,
                    source_hash CHAR(64) NOT NULL,
                    next_offset BIGINT NOT NULL,
                    datestamp DATETIME NOT NULL,
                    stored BIGINT NOT NULL,
                    skipped BIGINT NOT NULL,
                    invalid_fragments BIGINT NOT NULL,
                    status VARCHAR(16) NOT NULL,
                    consecutive_invalid INT NOT NULL DEFAULT 0,
                    PRIMARY KEY(id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
                SQL);
        } elseif (!$schema->getTable('museumplus_import_state')->hasColumn('consecutive_invalid')) {
            $this->addSql('ALTER TABLE museumplus_import_state ADD consecutive_invalid INT NOT NULL DEFAULT 0');
        }

        if (!$schema->hasTable('museumplus_import_errors')) {
            $this->addSql(<<<'SQL'
                CREATE TABLE museumplus_import_errors (
                    id BIGINT AUTO_INCREMENT NOT NULL,
                    source_hash CHAR(64) NOT NULL,
                    import_datestamp DATETIME NOT NULL,
                    source_offset BIGINT NOT NULL,
                    museumplus_id VARCHAR(64) NOT NULL,
                    error_message TEXT NOT NULL,
                    response_body LONGBLOB NOT NULL,
                    created_at DATETIME NOT NULL,
                    INDEX idx_museumplus_import_errors_run (source_hash, import_datestamp, source_offset),
                    PRIMARY KEY(id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
                SQL);
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Restored import tables may contain new data and must not be dropped.');
    }
}
