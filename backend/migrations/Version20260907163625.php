<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260907163625 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Move file-specific fields from datasets to dataset_versions; add datasets.description/updated_at.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE dataset_versions ADD original_filename VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE dataset_versions ADD mime_type VARCHAR(127) NOT NULL');
        $this->addSql('ALTER TABLE dataset_versions ADD file_size BIGINT NOT NULL');
        $this->addSql('ALTER TABLE dataset_versions ADD checksum VARCHAR(64) NOT NULL');
        $this->addSql('ALTER TABLE dataset_versions ADD has_formula_injection_risk BOOLEAN NOT NULL');
        $this->addSql('ALTER TABLE datasets ADD description TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE datasets ADD updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL');
        $this->addSql('ALTER TABLE datasets DROP original_filename');
        $this->addSql('ALTER TABLE datasets DROP mime_type');
        $this->addSql('ALTER TABLE datasets DROP size_bytes');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE dataset_versions DROP original_filename');
        $this->addSql('ALTER TABLE dataset_versions DROP mime_type');
        $this->addSql('ALTER TABLE dataset_versions DROP file_size');
        $this->addSql('ALTER TABLE dataset_versions DROP checksum');
        $this->addSql('ALTER TABLE dataset_versions DROP has_formula_injection_risk');
        $this->addSql('ALTER TABLE datasets ADD original_filename VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE datasets ADD mime_type VARCHAR(127) NOT NULL');
        $this->addSql('ALTER TABLE datasets ADD size_bytes BIGINT NOT NULL');
        $this->addSql('ALTER TABLE datasets DROP description');
        $this->addSql('ALTER TABLE datasets DROP updated_at');
    }
}
