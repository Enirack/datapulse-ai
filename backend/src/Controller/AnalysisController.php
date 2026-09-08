<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Enum\AnalysisType;
use App\Presentation\AnalysisPresenter;
use App\Repository\AnalysisRepository;
use App\Security\DatasetAccessGuard;
use App\Service\DatasetProfilingService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class AnalysisController
{
    public function __construct(
        private readonly DatasetAccessGuard $accessGuard,
        private readonly DatasetProfilingService $profilingService,
        private readonly AnalysisRepository $analysisRepository,
    ) {
    }

    #[Route('/api/datasets/{datasetId}/versions/{versionId}/profile', name: 'api_datasets_profile_trigger', methods: ['POST'])]
    public function trigger(string $datasetId, string $versionId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $dataset = $this->accessGuard->getOwnedDataset($datasetId, $user);
        $version = $this->accessGuard->getOwnedDatasetVersion($dataset, $versionId);

        $force = $request->query->getBoolean('force', false);

        $analysis = $this->profilingService->profile($version, $force);

        return new JsonResponse(AnalysisPresenter::present($analysis), 201);
    }

    #[Route('/api/datasets/{datasetId}/versions/{versionId}/profile', name: 'api_datasets_profile_show', methods: ['GET'])]
    public function show(string $datasetId, string $versionId, #[CurrentUser] User $user): JsonResponse
    {
        $dataset = $this->accessGuard->getOwnedDataset($datasetId, $user);
        $version = $this->accessGuard->getOwnedDatasetVersion($dataset, $versionId);

        $analysis = $this->analysisRepository->findLatestForVersion($version, AnalysisType::Profiling->value);
        if ($analysis === null) {
            throw new NotFoundHttpException('This dataset version has not been profiled yet.');
        }

        return new JsonResponse(AnalysisPresenter::present($analysis));
    }
}
