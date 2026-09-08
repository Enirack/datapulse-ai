<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260908095055 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add profiling result fields to analyses: result_schema_version, result (JSON), error_message, started_at.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE analyses ADD result_schema_version VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE analyses ADD result JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE analyses ADD error_message TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE analyses ADD started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE analyses DROP result_schema_version');
        $this->addSql('ALTER TABLE analyses DROP result');
        $this->addSql('ALTER TABLE analyses DROP error_message');
        $this->addSql('ALTER TABLE analyses DROP started_at');
    }
}
