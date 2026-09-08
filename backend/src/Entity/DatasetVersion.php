<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DatasetVersionKind;
use App\Repository\DatasetVersionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: DatasetVersionRepository::class)]
#[ORM\Table(name: 'dataset_versions')]
class DatasetVersion
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Dataset::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Dataset $dataset;

    #[ORM\Column]
    private int $versionNumber;

    #[ORM\Column(length: 20, enumType: DatasetVersionKind::class)]
    private DatasetVersionKind $kind;

    /**
     * Sanitized, display-only filename. Never used to build a filesystem path.
     */
    #[ORM\Column(length: 255)]
    private string $originalFilename;

    /**
     * Server-generated storage identifier (opaque). Resolved to an actual
     * filesystem path only inside DatasetStorageInterface implementations.
     */
    #[ORM\Column(length: 512)]
    private string $storagePath;

    #[ORM\Column(length: 127)]
    private string $mimeType;

    #[ORM\Column(type: Types::BIGINT)]
    private int $fileSize;

    /**
     * SHA-256 hex digest of the stored file's exact bytes.
     */
    #[ORM\Column(length: 64)]
    private string $checksum;

    /**
     * True if the format validator found cell values that look like
     * spreadsheet formulas (CSV injection risk). The original file is
     * never altered because of this; it is only recorded.
     */
    #[ORM\Column]
    private bool $hasFormulaInjectionRisk;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Dataset $dataset,
        int $versionNumber,
        DatasetVersionKind $kind,
        string $originalFilename,
        string $storagePath,
        string $mimeType,
        int $fileSize,
        string $checksum,
        bool $hasFormulaInjectionRisk,
    ) {
        $this->dataset = $dataset;
        $this->versionNumber = $versionNumber;
        $this->kind = $kind;
        $this->originalFilename = $originalFilename;
        $this->storagePath = $storagePath;
        $this->mimeType = $mimeType;
        $this->fileSize = $fileSize;
        $this->checksum = $checksum;
        $this->hasFormulaInjectionRisk = $hasFormulaInjectionRisk;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getDataset(): Dataset
    {
        return $this->dataset;
    }

    public function getVersionNumber(): int
    {
        return $this->versionNumber;
    }

    public function getKind(): DatasetVersionKind
    {
        return $this->kind;
    }

    public function getOriginalFilename(): string
    {
        return $this->originalFilename;
    }

    public function getStoragePath(): string
    {
        return $this->storagePath;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getFileSize(): int
    {
        return $this->fileSize;
    }

    public function getChecksum(): string
    {
        return $this->checksum;
    }

    public function hasFormulaInjectionRisk(): bool
    {
        return $this->hasFormulaInjectionRisk;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
