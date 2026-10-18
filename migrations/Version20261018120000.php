<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index the preserved Axiell priref for set-specific OAI identifiers; rebuild saved records after migrating';
    }

    public function up(Schema $schema): void
    {
        foreach (['records', 'records_import'] as $table) {
            $this->addSql('ALTER TABLE '.$table.' ADD axiell_priref VARCHAR(64) DEFAULT NULL, ADD UNIQUE INDEX uniq_records_axiell_priref (axiell_priref)');
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['records', 'records_import'] as $table) {
            $this->addSql('ALTER TABLE '.$table.' DROP INDEX uniq_records_axiell_priref, DROP axiell_priref');
        }
    }
}
