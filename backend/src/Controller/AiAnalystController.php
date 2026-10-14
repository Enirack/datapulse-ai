<?php

declare(strict_types=1);

namespace App\Controller;

use App\Ai\AiAnalystException;
use App\Entity\User;
use App\Presentation\AnalysisPresenter;
use App\Security\DatasetAccessGuard;
use App\Service\AiAnalystService;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class AiAnalystController
{
    public function __construct(
        private readonly DatasetAccessGuard $accessGuard,
        private readonly AiAnalystService $aiAnalystService,
    ) {
    }

    #[Route('/api/datasets/{datasetId}/versions/{versionId}/insights', name: 'api_datasets_insights_trigger', methods: ['POST'])]
    public function trigger(string $datasetId, string $versionId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        if (!$user->getPlan()->canUseAiAnalyst()) {
            return new JsonResponse([
                'status' => 'error',
                'code' => 'UPGRADE_REQUIRED_AI_ANALYST',
                'message' => "L'Analyste IA exécutif et les synthèses intelligentes sont réservés au plan Pro. Passez au plan Pro pour débloquer l'IA.",
                'upgrade_url' => '/pricing',
            ], 403);
        }

        $dataset = $this->accessGuard->getOwnedDataset($datasetId, $user);
        $version = $this->accessGuard->getOwnedDatasetVersion($dataset, $versionId);

        $force = $request->query->getBoolean('force', false);

        try {
            $analysis = $this->aiAnalystService->getOrGenerateInsights($version, $force);
        } catch (AiAnalystException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }

        return new JsonResponse(AnalysisPresenter::present($analysis), 201);
    }

    #[Route('/api/datasets/{datasetId}/versions/{versionId}/insights', name: 'api_datasets_insights_show', methods: ['GET'])]
    public function show(string $datasetId, string $versionId, #[CurrentUser] User $user): JsonResponse
    {
        $dataset = $this->accessGuard->getOwnedDataset($datasetId, $user);
        $version = $this->accessGuard->getOwnedDatasetVersion($dataset, $versionId);

        $analysis = $this->aiAnalystService->getLatestInsights($version);
        if ($analysis === null) {
            throw new NotFoundHttpException('No AI insights have been generated for this version yet.');
        }

        return new JsonResponse(AnalysisPresenter::present($analysis));
    }

    #[Route('/api/datasets/{datasetId}/versions/{versionId}/insights/ask', name: 'api_datasets_insights_ask', methods: ['POST'])]
    public function ask(string $datasetId, string $versionId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        if (!$user->getPlan()->canUseAiAnalyst()) {
            return new JsonResponse([
                'status' => 'error',
                'code' => 'UPGRADE_REQUIRED_AI_ANALYST',
                'message' => "Le chat décisionnel et les questions à l'Analyste IA sont réservés au plan Pro. Passez au plan Pro pour poser des questions.",
                'upgrade_url' => '/pricing',
            ], 403);
        }

        $dataset = $this->accessGuard->getOwnedDataset($datasetId, $user);
        $version = $this->accessGuard->getOwnedDatasetVersion($dataset, $versionId);

        try {
            $payload = $request->toArray();
        } catch (JsonException) {
            return new JsonResponse(['status' => 'error', 'message' => 'Invalid JSON body.'], 400);
        }

        $question = trim((string) ($payload['question'] ?? ''));
        if ($question === '') {
            return new JsonResponse(['status' => 'error', 'message' => 'A non-empty question is required.'], 422);
        }

        try {
            $response = $this->aiAnalystService->ask($version, $question);
        } catch (AiAnalystException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }

        return new JsonResponse($response);
    }
}
