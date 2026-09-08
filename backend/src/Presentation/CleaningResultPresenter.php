<?php

declare(strict_types=1);

namespace App\Presentation;

use App\Entity\Analysis;

/**
 * Shapes a cleaning Analysis into the response documented in
 * docs/data-cleaning.md — distinct from AnalysisPresenter's generic
 * shape because "sourceVersionId"/"resultVersionId"/"changed" are
 * specific to what a cleaning caller actually wants to see.
 */
final class CleaningResultPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function present(Analysis $analysis): array
    {
        $resultVersion = $analysis->getResultVersion();

        return [
            'analysisId' => (string) $analysis->getId(),
            'status' => $analysis->getStatus()->value,
            'sourceVersionId' => (string) $analysis->getDatasetVersion()->getId(),
            'resultVersionId' => $resultVersion !== null ? (string) $resultVersion->getId() : null,
            'changed' => $resultVersion !== null,
            'report' => $analysis->getResult(),
            'errorMessage' => $analysis->getErrorMessage(),
            'operations' => $analysis->getOperations(),
            'createdAt' => $analysis->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'startedAt' => $analysis->getStartedAt()?->format(\DateTimeInterface::ATOM),
            'completedAt' => $analysis->getCompletedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
