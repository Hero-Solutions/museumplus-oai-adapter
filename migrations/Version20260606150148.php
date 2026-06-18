<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260606150148 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create record tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE records (
                id INT AUTO_INCREMENT NOT NULL,
                museumplus_id VARCHAR(64) NOT NULL,
                oai_identifier VARCHAR(255) NOT NULL,
                set_spec VARCHAR(128) NOT NULL,
                datestamp DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                object_number VARCHAR(255) DEFAULT NULL,
                oai_xml LONGTEXT NOT NULL,
                museumplus_xml LONGTEXT NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_records_oai_identifier (oai_identifier),
                INDEX idx_records_set_datestamp (set_spec, datestamp),
                INDEX idx_records_museumplus_id (museumplus_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE records_import (
                id INT AUTO_INCREMENT NOT NULL,
                museumplus_id VARCHAR(64) NOT NULL,
                oai_identifier VARCHAR(255) NOT NULL,
                set_spec VARCHAR(128) NOT NULL,
                datestamp DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                object_number VARCHAR(255) DEFAULT NULL,
                oai_xml LONGTEXT NOT NULL,
                museumplus_xml LONGTEXT NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_records_import_oai_identifier (oai_identifier),
                INDEX idx_records_import_set_datestamp (set_spec, datestamp),
                INDEX idx_records_import_museumplus_id (museumplus_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE records_import');
        $this->addSql('DROP TABLE records');
    }
}
