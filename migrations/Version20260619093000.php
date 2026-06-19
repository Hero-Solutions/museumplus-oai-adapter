<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260619093000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add harvest ordering indexes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_records_datestamp_id ON records (datestamp, id)');
        $this->addSql('CREATE INDEX idx_records_datestamp_id ON records_import (datestamp, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_records_datestamp_id ON records_import');
        $this->addSql('DROP INDEX idx_records_datestamp_id ON records');
    }
}
