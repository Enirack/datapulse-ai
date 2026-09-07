<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AnalysisStatus;
use App\Repository\AnalysisRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: AnalysisRepository::class)]
#[ORM\Table(name: 'analyses')]
class Analysis
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: DatasetVersion::class)]
    #[ORM\JoinColumn(nullable: false)]
    private DatasetVersion $datasetVersion;

    #[ORM\Column(length: 50)]
    private string $type;

    #[ORM\Column(length: 20, enumType: AnalysisStatus::class)]
    private AnalysisStatus $status;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct(DatasetVersion $datasetVersion, string $type)
    {
        $this->datasetVersion = $datasetVersion;
        $this->type = $type;
        $this->status = AnalysisStatus::Pending;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getDatasetVersion(): DatasetVersion
    {
        return $this->datasetVersion;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getStatus(): AnalysisStatus
    {
        return $this->status;
    }

    public function setStatus(AnalysisStatus $status): void
    {
        $this->status = $status;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function markCompleted(): void
    {
        $this->status = AnalysisStatus::Completed;
        $this->completedAt = new \DateTimeImmutable();
    }
}
