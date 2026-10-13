<?php

declare(strict_types=1);

namespace App\Service;

use App\Ai\AiAnalystException;
use App\Ai\AiClientInterface;
use App\Entity\Analysis;
use App\Entity\DatasetVersion;
use App\Entity\Insight;
use App\Enum\AnalysisStatus;
use App\Enum\AnalysisType;
use App\Enum\InsightKind;
use App\Repository\AnalysisRepository;
use Doctrine\ORM\EntityManagerInterface;

final class AiAnalystService
{
    public function __construct(
        private readonly AnalysisRepository $analysisRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AiClientInterface $aiClient,
    ) {
    }

    /**
     * Generates or reuses structured AI insights for the given version.
     *
     * @throws AiAnalystException if prerequisites (profiling or analytics) are missing
     */
    public function getOrGenerateInsights(DatasetVersion $version, bool $force = false): Analysis
    {
        $profileAnalysis = $this->analysisRepository->findLatestForVersion($version, AnalysisType::Profiling->value);
        if (!$profileAnalysis || $profileAnalysis->getStatus() !== AnalysisStatus::Completed || !$profileAnalysis->getResult()) {
            throw new AiAnalystException('A completed profiling analysis is required before generating AI insights.');
        }

        $analyticsAnalysis = $this->analysisRepository->findLatestForVersion($version, AnalysisType::Analytics->value);
        if (!$analyticsAnalysis || $analyticsAnalysis->getStatus() !== AnalysisStatus::Completed || !$analyticsAnalysis->getResult()) {
            throw new AiAnalystException('A completed analytics analysis is required before generating AI insights.');
        }

        if (!$force) {
            $existing = $this->analysisRepository->findLatestForVersion($version, AnalysisType::AiInsights->value);
            if ($existing !== null && $existing->getStatus() === AnalysisStatus::Completed) {
                return $existing;
            }
        }

        $analysis = new Analysis($version, AnalysisType::AiInsights->value);
        $analysis->markRunning();
        $this->entityManager->persist($analysis);
        $this->entityManager->flush();

        try {
            $insightsData = $this->aiClient->generateInsights(
                $profileAnalysis->getResult(),
                $analyticsAnalysis->getResult()
            );

            $analysis->markCompleted($insightsData, '1.0');

            foreach ($insightsData['insights'] ?? [] as $item) {
                $kind = isset($item['kind']) ? InsightKind::tryFrom((string) $item['kind']) : null;
                $insight = new Insight(
                    $analysis,
                    (string) ($item['title'] ?? 'Insight'),
                    (string) ($item['description'] ?? ''),
                    $kind ?? InsightKind::ComputedFact
                );
                $this->entityManager->persist($insight);
            }

            $this->entityManager->flush();

            return $analysis;
        } catch (\Throwable $e) {
            $analysis->markFailed($e->getMessage());
            $this->entityManager->flush();
            throw new AiAnalystException($e->getMessage(), previous: $e);
        }
    }

    public function getLatestInsights(DatasetVersion $version): ?Analysis
    {
        return $this->analysisRepository->findLatestForVersion($version, AnalysisType::AiInsights->value);
    }

    /**
     * @return array<string, mixed>
     */
    public function ask(DatasetVersion $version, string $question): array
    {
        $profileAnalysis = $this->analysisRepository->findLatestForVersion($version, AnalysisType::Profiling->value);
        $analyticsAnalysis = $this->analysisRepository->findLatestForVersion($version, AnalysisType::Analytics->value);

        if (!$profileAnalysis || $profileAnalysis->getStatus() !== AnalysisStatus::Completed || !$profileAnalysis->getResult()
            || !$analyticsAnalysis || $analyticsAnalysis->getStatus() !== AnalysisStatus::Completed || !$analyticsAnalysis->getResult()) {
            throw new AiAnalystException('Dataset must have completed profile and analytics before asking questions.');
        }

        return $this->aiClient->askQuestion(
            $profileAnalysis->getResult(),
            $analyticsAnalysis->getResult(),
            $question
        );
    }
}
