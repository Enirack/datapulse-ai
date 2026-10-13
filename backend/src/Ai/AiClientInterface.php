<?php

declare(strict_types=1);

namespace App\Ai;

interface AiClientInterface
{
    /**
     * Synthesizes an executive narrative summary and structured insights
     * (computed facts, statistical interpretations, recommendations)
     * grounded entirely on deterministic profiling and analytics results.
     *
     * @param array<string, mixed> $profileResult the JSON profiling result (Phase 3 contract)
     * @param array<string, mixed> $analyticsResult the JSON analytics result (Phase 5 contract)
     * @return array<string, mixed> the synthesized insights conforming to docs/ai-analyst.md
     */
    public function generateInsights(array $profileResult, array $analyticsResult): array;

    /**
     * Answers a user's analytical question strictly grounded in the
     * dataset's computed profile and analytics metrics.
     *
     * @param array<string, mixed> $profileResult
     * @param array<string, mixed> $analyticsResult
     * @param string $question
     * @return array<string, mixed> {"question": string, "answer": string, "grounded": bool, "referencedMetrics": array}
     */
    public function askQuestion(array $profileResult, array $analyticsResult, string $question): array;
}
