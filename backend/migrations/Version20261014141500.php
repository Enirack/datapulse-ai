<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014141500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add plan column with default free to users table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE users ADD plan VARCHAR(20) DEFAULT 'free' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP plan');
    }
}
