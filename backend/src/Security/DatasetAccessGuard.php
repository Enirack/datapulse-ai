<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Dataset;
use App\Entity\DatasetVersion;
use App\Entity\User;
use App\Repository\DatasetRepository;
use App\Repository\DatasetVersionRepository;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Centralizes the "404 if it doesn't exist at all, 403 if it exists but
 * isn't yours" ownership check used by every dataset-scoped endpoint.
 */
final class DatasetAccessGuard
{
    public function __construct(
        private readonly DatasetRepository $datasetRepository,
        private readonly DatasetVersionRepository $versionRepository,
    ) {
    }

    public function getOwnedDataset(string $id, User $user): Dataset
    {
        if (!Uuid::isValid($id)) {
            throw new NotFoundHttpException('Dataset not found.');
        }

        $dataset = $this->datasetRepository->find(Uuid::fromString($id));
        if ($dataset === null) {
            throw new NotFoundHttpException('Dataset not found.');
        }

        if (!$dataset->getOwner()->getId()->equals($user->getId())) {
            throw new AccessDeniedHttpException('You do not have access to this dataset.');
        }

        return $dataset;
    }

    /**
     * Also verifies the version actually belongs to the given (already
     * ownership-checked) dataset — a valid version id for a *different*
     * dataset must not be usable here.
     */
    public function getOwnedDatasetVersion(Dataset $dataset, string $versionId): DatasetVersion
    {
        if (!Uuid::isValid($versionId)) {
            throw new NotFoundHttpException('Dataset version not found.');
        }

        $version = $this->versionRepository->find(Uuid::fromString($versionId));
        if ($version === null || !$version->getDataset()->getId()->equals($dataset->getId())) {
            throw new NotFoundHttpException('Dataset version not found.');
        }

        return $version;
    }
}
