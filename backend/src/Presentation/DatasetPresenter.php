<?php

declare(strict_types=1);

namespace App\Presentation;

use App\Entity\Dataset;
use App\Entity\DatasetVersion;

final class DatasetPresenter
{
    /**
     * @param list<DatasetVersion> $versions
     *
     * @return array<string, mixed>
     */
    public static function present(Dataset $dataset, array $versions = []): array
    {
        return [
            'id' => (string) $dataset->getId(),
            'name' => $dataset->getName(),
            'description' => $dataset->getDescription(),
            'status' => $dataset->getStatus()->value,
            'createdAt' => $dataset->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $dataset->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            'versions' => array_map(DatasetVersionPresenter::present(...), $versions),
        ];
    }
}
