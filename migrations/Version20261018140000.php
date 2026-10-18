<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Link mapped records to original response bodies in the local raw XML archive';
    }

    public function up(Schema $schema): void
    {
        foreach (['records', 'records_import'] as $table) {
            $this->addSql('ALTER TABLE '.$table.' ADD raw_response_id VARCHAR(64) DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['records', 'records_import'] as $table) {
            $this->addSql('ALTER TABLE '.$table.' DROP raw_response_id');
        }
    }
}
