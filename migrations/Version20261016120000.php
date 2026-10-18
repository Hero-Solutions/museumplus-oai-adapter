<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261016120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store native mapping output and overlapping, explicit publication sets; rebuild before harvesting';
    }

    public function up(Schema $schema): void
    {
        foreach (['records', 'records_import'] as $table) {
            $this->addSql('ALTER TABLE '.$table.' ADD oai_xml_native LONGTEXT DEFAULT NULL, ADD publish_msk TINYINT(1) NOT NULL DEFAULT 0, ADD publish_brugge TINYINT(1) NOT NULL DEFAULT 0, ADD publish_muzee TINYINT(1) NOT NULL DEFAULT 0, ADD publish_vkc TINYINT(1) NOT NULL DEFAULT 0');
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['records', 'records_import'] as $table) {
            $this->addSql('ALTER TABLE '.$table.' DROP oai_xml_native, DROP publish_msk, DROP publish_brugge, DROP publish_muzee, DROP publish_vkc');
        }
    }
}
