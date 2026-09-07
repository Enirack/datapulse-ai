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

    #[ORM\Column(length: 512)]
    private string $storagePath;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Dataset $dataset, int $versionNumber, DatasetVersionKind $kind, string $storagePath)
    {
        $this->dataset = $dataset;
        $this->versionNumber = $versionNumber;
        $this->kind = $kind;
        $this->storagePath = $storagePath;
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

    public function getStoragePath(): string
    {
        return $this->storagePath;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
