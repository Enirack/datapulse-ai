<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Distinguishes what kind of statement an insight makes, so the UI and the
 * AI layer never blur a computed fact with an interpretation or a recommendation.
 */
enum InsightKind: string
{
    case ComputedFact = 'computed_fact';
    case StatisticalInterpretation = 'statistical_interpretation';
    case Recommendation = 'recommendation';
}
