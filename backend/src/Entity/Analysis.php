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

    /**
     * Version of the JSON shape held in `result`, e.g. "1.0" for the
     * profiling contract documented in docs/data-profiling.md. Only set
     * once the analysis has a result.
     */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $resultSchemaVersion = null;

    /**
     * The structured analysis result (e.g. a profiling report). Stored as
     * JSONB — queryable if ever needed, and a natural fit for a result
     * whose shape is already a versioned JSON contract.
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $result = null;

    /**
     * Sanitized, human-readable failure reason. Never the raw exception
     * message or a stack trace — see DatasetProfilingService.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

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

    public function getResultSchemaVersion(): ?string
    {
        return $this->resultSchemaVersion;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getResult(): ?array
    {
        return $this->result;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function markRunning(): void
    {
        $this->status = AnalysisStatus::Running;
        $this->startedAt = new \DateTimeImmutable();
    }

    /**
     * @param array<string, mixed> $result
     */
    public function markCompleted(array $result, string $resultSchemaVersion): void
    {
        $this->status = AnalysisStatus::Completed;
        $this->result = $result;
        $this->resultSchemaVersion = $resultSchemaVersion;
        $this->completedAt = new \DateTimeImmutable();
    }

    public function markFailed(string $errorMessage): void
    {
        $this->status = AnalysisStatus::Failed;
        $this->errorMessage = $errorMessage;
        $this->completedAt = new \DateTimeImmutable();
    }
}
