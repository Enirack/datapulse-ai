<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260908110053 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add cleaning support: dataset_versions.source_version_id (lineage), analyses.operations/pipeline_fingerprint/result_version_id.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE analyses ADD operations JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE analyses ADD pipeline_fingerprint VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE analyses ADD result_version_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE analyses ADD CONSTRAINT FK_AC86883CB3E72DDA FOREIGN KEY (result_version_id) REFERENCES dataset_versions (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_AC86883CB3E72DDA ON analyses (result_version_id)');
        $this->addSql('ALTER TABLE dataset_versions ADD source_version_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE dataset_versions ADD CONSTRAINT FK_8648765B37085621 FOREIGN KEY (source_version_id) REFERENCES dataset_versions (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_8648765B37085621 ON dataset_versions (source_version_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE analyses DROP CONSTRAINT FK_AC86883CB3E72DDA');
        $this->addSql('DROP INDEX IDX_AC86883CB3E72DDA');
        $this->addSql('ALTER TABLE analyses DROP operations');
        $this->addSql('ALTER TABLE analyses DROP pipeline_fingerprint');
        $this->addSql('ALTER TABLE analyses DROP result_version_id');
        $this->addSql('ALTER TABLE dataset_versions DROP CONSTRAINT FK_8648765B37085621');
        $this->addSql('DROP INDEX IDX_8648765B37085621');
        $this->addSql('ALTER TABLE dataset_versions DROP source_version_id');
    }
}
