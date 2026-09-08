<?php

declare(strict_types=1);

namespace App\Presentation;

use App\Entity\Analysis;

final class AnalysisPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function present(Analysis $analysis): array
    {
        return [
            'id' => (string) $analysis->getId(),
            'datasetVersionId' => (string) $analysis->getDatasetVersion()->getId(),
            'type' => $analysis->getType(),
            'status' => $analysis->getStatus()->value,
            'resultSchemaVersion' => $analysis->getResultSchemaVersion(),
            'result' => $analysis->getResult(),
            'errorMessage' => $analysis->getErrorMessage(),
            'createdAt' => $analysis->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'startedAt' => $analysis->getStartedAt()?->format(\DateTimeInterface::ATOM),
            'completedAt' => $analysis->getCompletedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
