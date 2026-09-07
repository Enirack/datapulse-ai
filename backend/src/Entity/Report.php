<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ReportFormat;
use App\Repository\ReportRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ReportRepository::class)]
#[ORM\Table(name: 'reports')]
class Report
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Dataset::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Dataset $dataset;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(length: 10, enumType: ReportFormat::class)]
    private ReportFormat $format;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $generatedAt;

    public function __construct(Dataset $dataset, string $title, ReportFormat $format)
    {
        $this->dataset = $dataset;
        $this->title = $title;
        $this->format = $format;
        $this->generatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getDataset(): Dataset
    {
        return $this->dataset;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getFormat(): ReportFormat
    {
        return $this->format;
    }

    public function getGeneratedAt(): \DateTimeImmutable
    {
        return $this->generatedAt;
    }
}
