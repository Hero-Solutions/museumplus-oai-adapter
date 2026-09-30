<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260930140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Log automatically skipped MuseumPlus records and persist the consecutive failure limit';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE museumplus_import_state ADD consecutive_invalid INT NOT NULL DEFAULT 0');
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

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE museumplus_import_errors');
        $this->addSql('ALTER TABLE museumplus_import_state DROP consecutive_invalid');
    }
}
