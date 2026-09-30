<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260930120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist MuseumPlus import progress for resuming interrupted full imports';
    }

    public function up(Schema $schema): void
    {
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
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS museumplus_import_state_next');
        $this->addSql('DROP TABLE IF EXISTS museumplus_import_state_old');
        $this->addSql('DROP TABLE museumplus_import_state');
    }
}
