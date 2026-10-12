<?php

declare(strict_types=1);

namespace App\Controller;

use App\Cleaning\CleaningRecommendationService;
use App\Cleaning\Operation;
use App\Cleaning\PipelineParser;
use App\Cleaning\PipelineValidationException;
use App\Entity\User;
use App\Enum\AnalysisStatus;
use App\Enum\AnalysisType;
use App\Presentation\CleaningResultPresenter;
use App\Profiling\DataEngineException;
use App\Repository\AnalysisRepository;
use App\Security\DatasetAccessGuard;
use App\Service\CleaningService;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class CleaningController
{
    public function __construct(
        private readonly DatasetAccessGuard $accessGuard,
        private readonly CleaningService $cleaningService,
        private readonly CleaningRecommendationService $recommendationService,
        private readonly PipelineParser $pipelineParser,
        private readonly AnalysisRepository $analysisRepository,
    ) {
    }

    #[Route('/api/datasets/{datasetId}/versions/{versionId}/cleaning-recommendations', name: 'api_datasets_cleaning_recommendations', methods: ['GET'])]
    public function recommendations(string $datasetId, string $versionId, #[CurrentUser] User $user): JsonResponse
    {
        $dataset = $this->accessGuard->getOwnedDataset($datasetId, $user);
        $version = $this->accessGuard->getOwnedDatasetVersion($dataset, $versionId);

        $profilingAnalysis = $this->analysisRepository->findLatestForVersion($version, AnalysisType::Profiling->value);
        if ($profilingAnalysis === null || $profilingAnalysis->getStatus() !== AnalysisStatus::Completed) {
            throw new NotFoundHttpException('This dataset version has not been successfully profiled yet — profile it first.');
        }

        return new JsonResponse([
            'datasetVersionId' => (string) $version->getId(),
            'recommendations' => $this->recommendationService->recommend($profilingAnalysis->getResult() ?? []),
        ]);
    }

    #[Route('/api/datasets/{datasetId}/versions/{versionId}/clean/preview', name: 'api_datasets_clean_preview', methods: ['POST'])]
    public function preview(string $datasetId, string $versionId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $dataset = $this->accessGuard->getOwnedDataset($datasetId, $user);
        $version = $this->accessGuard->getOwnedDatasetVersion($dataset, $versionId);

        $operations = $this->parseOperations($request);

        try {
            $result = $this->cleaningService->preview($version, $operations);
        } catch (DataEngineException $e) {
            return new JsonResponse(['status' => 'error', 'message' => $e->getMessage()], $e->httpStatusCode);
        }

        return new JsonResponse($result);
    }

    #[Route('/api/datasets/{datasetId}/versions/{versionId}/clean', name: 'api_datasets_clean_trigger', methods: ['POST'])]
    public function clean(string $datasetId, string $versionId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $dataset = $this->accessGuard->getOwnedDataset($datasetId, $user);
        $version = $this->accessGuard->getOwnedDatasetVersion($dataset, $versionId);

        $operations = $this->parseOperations($request);
        $force = $request->query->getBoolean('force', false);

        $analysis = $this->cleaningService->clean($version, $operations, $force);

        return new JsonResponse(CleaningResultPresenter::present($analysis), 201);
    }

    #[Route('/api/datasets/{datasetId}/versions/{versionId}/clean', name: 'api_datasets_clean_show', methods: ['GET'])]
    public function show(string $datasetId, string $versionId, #[CurrentUser] User $user): JsonResponse
    {
        $dataset = $this->accessGuard->getOwnedDataset($datasetId, $user);
        $version = $this->accessGuard->getOwnedDatasetVersion($dataset, $versionId);

        $analysis = $this->analysisRepository->findLatestForVersion($version, AnalysisType::Cleaning->value);
        if ($analysis === null) {
            throw new NotFoundHttpException('This dataset version has not been cleaned yet.');
        }

        return new JsonResponse(CleaningResultPresenter::present($analysis));
    }

    /**
     * @return list<Operation>
     */
    private function parseOperations(Request $request): array
    {
        try {
            $payload = $request->toArray();
        } catch (JsonException) {
            throw new PipelineValidationException('Invalid JSON body.');
        }

        return $this->pipelineParser->parse($payload['operations'] ?? null);
    }
}
