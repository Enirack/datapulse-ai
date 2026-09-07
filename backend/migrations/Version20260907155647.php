<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260907155647 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial domain schema: users, datasets, dataset_versions, analyses, insights, reports.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE analyses (id UUID NOT NULL, type VARCHAR(50) NOT NULL, status VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, dataset_version_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_AC86883C6E470E0A ON analyses (dataset_version_id)');
        $this->addSql('CREATE TABLE dataset_versions (id UUID NOT NULL, version_number INT NOT NULL, kind VARCHAR(20) NOT NULL, storage_path VARCHAR(512) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, dataset_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_8648765BD47C2D1B ON dataset_versions (dataset_id)');
        $this->addSql('CREATE TABLE datasets (id UUID NOT NULL, name VARCHAR(255) NOT NULL, original_filename VARCHAR(255) NOT NULL, mime_type VARCHAR(127) NOT NULL, size_bytes BIGINT NOT NULL, status VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, owner_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_9D6ABD9E7E3C61F9 ON datasets (owner_id)');
        $this->addSql('CREATE TABLE insights (id UUID NOT NULL, title VARCHAR(255) NOT NULL, description TEXT NOT NULL, kind VARCHAR(30) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, analysis_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_AF1F0447941003F ON insights (analysis_id)');
        $this->addSql('CREATE TABLE reports (id UUID NOT NULL, title VARCHAR(255) NOT NULL, format VARCHAR(10) NOT NULL, generated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, dataset_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_F11FA745D47C2D1B ON reports (dataset_id)');
        $this->addSql('CREATE TABLE users (id UUID NOT NULL, email VARCHAR(180) NOT NULL, password VARCHAR(255) NOT NULL, roles JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_email ON users (email)');
        $this->addSql('ALTER TABLE analyses ADD CONSTRAINT FK_AC86883C6E470E0A FOREIGN KEY (dataset_version_id) REFERENCES dataset_versions (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE dataset_versions ADD CONSTRAINT FK_8648765BD47C2D1B FOREIGN KEY (dataset_id) REFERENCES datasets (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE datasets ADD CONSTRAINT FK_9D6ABD9E7E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE insights ADD CONSTRAINT FK_AF1F0447941003F FOREIGN KEY (analysis_id) REFERENCES analyses (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE reports ADD CONSTRAINT FK_F11FA745D47C2D1B FOREIGN KEY (dataset_id) REFERENCES datasets (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE analyses DROP CONSTRAINT FK_AC86883C6E470E0A');
        $this->addSql('ALTER TABLE dataset_versions DROP CONSTRAINT FK_8648765BD47C2D1B');
        $this->addSql('ALTER TABLE datasets DROP CONSTRAINT FK_9D6ABD9E7E3C61F9');
        $this->addSql('ALTER TABLE insights DROP CONSTRAINT FK_AF1F0447941003F');
        $this->addSql('ALTER TABLE reports DROP CONSTRAINT FK_F11FA745D47C2D1B');
        $this->addSql('DROP TABLE analyses');
        $this->addSql('DROP TABLE dataset_versions');
        $this->addSql('DROP TABLE datasets');
        $this->addSql('DROP TABLE insights');
        $this->addSql('DROP TABLE reports');
        $this->addSql('DROP TABLE users');
    }
}
