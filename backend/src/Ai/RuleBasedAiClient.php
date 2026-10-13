<?php

declare(strict_types=1);

namespace App\Ai;

use Symfony\Component\Uid\Uuid;

final class RuleBasedAiClient implements AiClientInterface
{
    public function generateInsights(array $profileResult, array $analyticsResult): array
    {
        $datasetProfile = $analyticsResult['dataset'] ?? $profileResult['dataset'] ?? [];
        $rows = (int) ($datasetProfile['rows'] ?? $profileResult['rowCount'] ?? 0);
        $cols = (int) ($datasetProfile['columns'] ?? $profileResult['columnCount'] ?? 0);
        $totalNullCells = (int) ($datasetProfile['totalNullCells'] ?? 0);
        $nullPercentage = (float) ($datasetProfile['nullPercentage'] ?? 0.0);
        $duplicateRows = (int) ($datasetProfile['duplicateRowCount'] ?? $profileResult['duplicateRows'] ?? 0);
        $duplicateRowPercentage = (float) ($datasetProfile['duplicateRowPercentage'] ?? ((float) ($profileResult['duplicateRowsRatio'] ?? 0.0) * 100.0));

        $healthScore = $this->calculateHealthScore($nullPercentage, $duplicateRowPercentage);

        $numericList = $analyticsResult['numeric'] ?? [];
        $categoricalList = $analyticsResult['categorical'] ?? [];
        $temporalList = $analyticsResult['temporal'] ?? [];
        $correlations = $analyticsResult['correlations'] ?? null;
        $scatter = $analyticsResult['scatter'] ?? [];

        // 1. Identify highlights
        $topCorrelationText = null;
        $topCorrelationData = null;
        if (is_array($correlations) && !empty($correlations['matrix']) && !empty($correlations['columns'])) {
            $matrix = $correlations['matrix'];
            $colNames = $correlations['columns'];
            $maxCorr = -2.0;

            for ($i = 0; $i < count($colNames); $i++) {
                for ($j = $i + 1; $j < count($colNames); $j++) {
                    $val = $matrix[$i][$j] ?? null;
                    if ($val !== null && abs((float) $val) > $maxCorr) {
                        $maxCorr = abs((float) $val);
                        $topCorrelationText = sprintf('%s vs %s (r = %.2f)', $colNames[$i], $colNames[$j], (float) $val);
                        $topCorrelationData = [
                            'col1' => $colNames[$i],
                            'col2' => $colNames[$j],
                            'r' => (float) $val,
                        ];
                    }
                }
            }
        }

        $dominantCategoryText = null;
        if (!empty($categoricalList[0]['statistics']['topValues'][0])) {
            $firstCat = $categoricalList[0];
            $topVal = $firstCat['statistics']['topValues'][0];
            $dominantCategoryText = sprintf('%s: %s (%.1f%%)', $firstCat['column'], $topVal['value'], (float) $topVal['percentage']);
        }

        // 2. Executive summary
        $completeness = round(100.0 - $nullPercentage, 2);
        $summaryParts = [];
        $summaryParts[] = sprintf(
            'This dataset contains %s records across %d columns with an overall completeness rate of %.2f%%.',
            number_format($rows),
            $cols,
            $completeness
        );

        if ($topCorrelationText !== null) {
            $summaryParts[] = sprintf('The most prominent linear relationship detected is between %s.', $topCorrelationText);
        }

        if ($dominantCategoryText !== null) {
            $summaryParts[] = sprintf('In categorical dimensions, the most prevalent group is %s.', $dominantCategoryText);
        }

        if (!empty($temporalList[0]['statistics']['minDate']) && !empty($temporalList[0]['statistics']['maxDate'])) {
            $t = $temporalList[0];
            $summaryParts[] = sprintf(
                'Temporal coverage for "%s" spans from %s to %s.',
                $t['column'],
                $t['statistics']['minDate'],
                $t['statistics']['maxDate']
            );
        }

        $executiveSummary = implode(' ', $summaryParts);

        // 3. Structured insights
        $insights = [];

        // A. Computed Facts
        if ($topCorrelationData !== null && abs($topCorrelationData['r']) >= 0.3) {
            $direction = $topCorrelationData['r'] > 0 ? 'positive' : 'negative';
            $strength = abs($topCorrelationData['r']) >= 0.7 ? 'Strong' : 'Moderate';
            $insights[] = [
                'id' => Uuid::v4()->toRfc4122(),
                'kind' => 'computed_fact',
                'title' => sprintf('%s %s Correlation: %s and %s', $strength, ucfirst($direction), $topCorrelationData['col1'], $topCorrelationData['col2']),
                'description' => sprintf(
                    'A Pearson correlation coefficient of %.2f was computed between %s and %s across valid numeric observations.',
                    $topCorrelationData['r'],
                    $topCorrelationData['col1'],
                    $topCorrelationData['col2']
                ),
                'category' => 'correlation',
                'metrics' => [
                    'columns' => [$topCorrelationData['col1'], $topCorrelationData['col2']],
                    'correlation' => $topCorrelationData['r'],
                ],
            ];
        }

        if ($totalNullCells > 0) {
            $insights[] = [
                'id' => Uuid::v4()->toRfc4122(),
                'kind' => 'computed_fact',
                'title' => 'Missing Data Footprint',
                'description' => sprintf(
                    'The dataset exhibits %s unpopulated cells (%.2f%% of total cells across all rows).',
                    number_format($totalNullCells),
                    $nullPercentage
                ),
                'category' => 'quality',
                'metrics' => [
                    'totalNullCells' => $totalNullCells,
                    'nullPercentage' => $nullPercentage,
                ],
            ];
        } else {
            $insights[] = [
                'id' => Uuid::v4()->toRfc4122(),
                'kind' => 'computed_fact',
                'title' => 'Complete Observation Matrix',
                'description' => 'Zero missing or null cells detected across all attributes and rows.',
                'category' => 'quality',
                'metrics' => [
                    'completeness' => 100.0,
                ],
            ];
        }

        // B. Statistical Interpretations
        foreach ($numericList as $num) {
            $stats = $num['statistics'] ?? [];
            $mean = $stats['mean'] ?? null;
            $median = $stats['median'] ?? null;
            $outliers = $num['boxplot']['outlierCount'] ?? 0;

            if ($mean !== null && $median !== null && abs($mean - $median) > 0.05 * abs($median ?: 1.0)) {
                $skew = $mean > $median ? 'right-skewed (positive skew)' : 'left-skewed (negative skew)';
                $insights[] = [
                    'id' => Uuid::v4()->toRfc4122(),
                    'kind' => 'statistical_interpretation',
                    'title' => sprintf('Distribution Asymmetry in %s', $num['column']),
                    'description' => sprintf(
                        'The mean (%.2f) diverges from the median (%.2f) indicating a %s distribution influenced by high-variance tails.',
                        (float) $mean,
                        (float) $median,
                        $skew
                    ),
                    'category' => 'distribution',
                    'metrics' => [
                        'column' => $num['column'],
                        'mean' => $mean,
                        'median' => $median,
                        'outliers' => $outliers,
                    ],
                ];
                break; // One skew insight is enough
            }
        }

        if ($duplicateRows > 0) {
            $insights[] = [
                'id' => Uuid::v4()->toRfc4122(),
                'kind' => 'statistical_interpretation',
                'title' => 'Identified Row Redundancy',
                'description' => sprintf(
                    '%d rows (%.2f%%) are exact duplicates of existing records, slightly inflating observation counts.',
                    $duplicateRows,
                    $duplicateRowPercentage
                ),
                'category' => 'quality',
                'metrics' => [
                    'duplicateCount' => $duplicateRows,
                    'duplicatePercentage' => $duplicateRowPercentage,
                ],
            ];
        }

        // C. Recommendations
        if ($duplicateRows > 0) {
            $insights[] = [
                'id' => Uuid::v4()->toRfc4122(),
                'kind' => 'recommendation',
                'title' => 'Deduplicate Records Prior to Modeling',
                'description' => 'Apply the automated "Remove duplicates" cleaning operation to preserve statistical independence and prevent bias in model training.',
                'category' => 'cleaning',
                'metrics' => [
                    'recommendedAction' => 'remove_duplicate_rows',
                    'affectedRows' => $duplicateRows,
                ],
            ];
        }

        foreach ($numericList as $num) {
            $missing = $num['statistics']['missingCount'] ?? 0;
            $mean = $num['statistics']['mean'] ?? null;
            $median = $num['statistics']['median'] ?? null;
            if ($missing > 0) {
                $strategy = ($mean !== null && $median !== null && abs($mean - $median) > 0.1 * abs($median ?: 1.0))
                    ? 'median imputation'
                    : 'mean imputation';
                $insights[] = [
                    'id' => Uuid::v4()->toRfc4122(),
                    'kind' => 'recommendation',
                    'title' => sprintf('Handle Missing Values in %s', $num['column']),
                    'description' => sprintf(
                        'Column %s has %d missing entries. Due to its distribution characteristics, %s is recommended over row deletion.',
                        $num['column'],
                        $missing,
                        $strategy
                    ),
                    'category' => 'quality',
                    'metrics' => [
                        'column' => $num['column'],
                        'missingCount' => $missing,
                        'strategy' => $strategy,
                    ],
                ];
                break;
            }
        }

        $hasRecommendation = false;
        foreach ($insights as $item) {
            if ($item['kind'] === 'recommendation') {
                $hasRecommendation = true;
                break;
            }
        }

        if (!$hasRecommendation) {
            $insights[] = [
                'id' => Uuid::v4()->toRfc4122(),
                'kind' => 'recommendation',
                'title' => 'Ready for Downstream Modeling and Analysis',
                'description' => 'The dataset exhibits high completeness and hygiene. You can proceed directly with modeling, feature engineering, and downstream exports.',
                'category' => 'modeling',
                'metrics' => [
                    'healthScore' => $healthScore,
                    'completeness' => $completeness,
                ],
            ];
        }

        // 4. Suggested Questions
        $suggestedQuestions = [
            'What are the strongest relationships between variables in this dataset?',
            'What is the overall data quality and missing value distribution?',
        ];
        if (!empty($numericList[0]['column'])) {
            $suggestedQuestions[] = sprintf('What is the distribution and spread of %s?', $numericList[0]['column']);
        }
        if (!empty($categoricalList[0]['column'])) {
            $suggestedQuestions[] = sprintf('How are categories distributed in %s?', $categoricalList[0]['column']);
        }

        return [
            'schemaVersion' => '1.0',
            'healthScore' => $healthScore,
            'executiveSummary' => $executiveSummary,
            'highlights' => [
                'totalRows' => $rows,
                'totalColumns' => $cols,
                'completenessPercentage' => $completeness,
                'dominantCategory' => $dominantCategoryText,
                'topCorrelation' => $topCorrelationText,
            ],
            'insights' => $insights,
            'suggestedQuestions' => $suggestedQuestions,
        ];
    }

    public function askQuestion(array $profileResult, array $analyticsResult, string $question): array
    {
        $q = strtolower(trim($question));
        $dataset = $analyticsResult['dataset'] ?? $profileResult['dataset'] ?? [];
        $rows = (int) ($dataset['rows'] ?? $profileResult['rowCount'] ?? 0);
        $cols = (int) ($dataset['columns'] ?? $profileResult['columnCount'] ?? 0);
        $nullCells = (int) ($dataset['totalNullCells'] ?? 0);
        $nullPct = (float) ($dataset['nullPercentage'] ?? 0.0);

        $numeric = $analyticsResult['numeric'] ?? [];
        $correlations = $analyticsResult['correlations'] ?? null;
        $categorical = $analyticsResult['categorical'] ?? [];

        // Check for outliers / anomalies questions first
        if (str_contains($q, 'outlier') || str_contains($q, 'anomal') || str_contains($q, 'atypique')) {
            $outlierReport = [];
            foreach ($numeric as $col) {
                $cnt = (int) ($col['boxplot']['outlierCount'] ?? $col['outliers']['count'] ?? 0);
                if ($cnt > 0) {
                    $outlierReport[] = sprintf('%s (%d outliers)', $col['column'], $cnt);
                }
            }

            if (!empty($outlierReport)) {
                $ans = sprintf('The following numeric columns exhibit outlier values according to IQR bounds: %s.', implode(', ', $outlierReport));
                return $this->formatAnswer($question, $ans, [
                    'columnsWithOutliers' => $outlierReport,
                ]);
            }

            return $this->formatAnswer($question, 'No extreme outliers were flagged across the analyzed numeric features using standard 1.5x IQR boundaries.', [
                'columnsWithOutliers' => [],
            ]);
        }

        // Check for correlation questions
        if (str_contains($q, 'correl') || str_contains($q, 'relation') || str_contains($q, 'liaison') || preg_match('/\blier\b|\bliés\b|\bliée\b/', $q) === 1) {
            if (is_array($correlations) && !empty($correlations['matrix']) && !empty($correlations['columns'])) {
                $matrix = $correlations['matrix'];
                $colNames = $correlations['columns'];
                $pairs = [];

                for ($i = 0; $i < count($colNames); $i++) {
                    for ($j = $i + 1; $j < count($colNames); $j++) {
                        $val = (float) ($matrix[$i][$j] ?? 0);
                        if (abs($val) >= 0.2) {
                            $pairs[] = sprintf('%s vs %s (r = %.2f)', $colNames[$i], $colNames[$j], $val);
                        }
                    }
                }

                if (!empty($pairs)) {
                    $ans = sprintf('The key linear relationships identified are: %s.', implode(', ', array_slice($pairs, 0, 3)));
                    return $this->formatAnswer($question, $ans, [
                        'correlations' => array_slice($pairs, 0, 3),
                    ]);
                }
            }
            return $this->formatAnswer($question, 'No strong linear correlations (|r| >= 0.20) were found between numeric attributes in this dataset.', [
                'correlations' => [],
            ]);
        }

        // Check for missing data / quality questions
        if (str_contains($q, 'miss') || str_contains($q, 'manqu') || str_contains($q, 'qualit') || str_contains($q, 'null') || str_contains($q, 'health')) {
            $completeness = round(100.0 - $nullPct, 2);
            $ans = sprintf(
                'Data completeness is at %.2f%%. There are %s missing cells out of %s total observations across %d columns.',
                $completeness,
                number_format($nullCells),
                number_format((int) ($dataset['totalCells'] ?? ($rows * max(1, $cols)))),
                $cols
            );
            return $this->formatAnswer($question, $ans, [
                'completenessPercentage' => $completeness,
                'totalNullCells' => $nullCells,
            ]);
        }


        // Check for category questions
        if (str_contains($q, 'categor') || str_contains($q, 'group') || str_contains($q, 'segment') || str_contains($q, 'classe')) {
            if (!empty($categorical)) {
                $first = $categorical[0];
                $top = $first['statistics']['topValues'][0] ?? null;
                $ans = sprintf('The dataset includes categorical column "%s"', $first['column']);
                if ($top) {
                    $ans .= sprintf(' where the largest group is "%s" (%.1f%%).', $top['value'], (float) $top['percentage']);
                } else {
                    $ans .= '.';
                }
                return $this->formatAnswer($question, $ans, [
                    'category' => $first['column'],
                ]);
            }
        }

        // Generic / Executive fallback
        $ans = sprintf(
            'This dataset contains %s records and %d columns. Completeness stands at %.2f%% with %s null cells. You can explore distributions, correlations, or ask specific questions regarding missingness and outliers.',
            number_format($rows),
            $cols,
            round(100.0 - $nullPct, 2),
            number_format($nullCells)
        );

        return $this->formatAnswer($question, $ans, [
            'rows' => $rows,
            'columns' => $cols,
            'nullPercentage' => $nullPct,
        ]);
    }

    /**
     * @param array<string, mixed> $metrics
     * @return array<string, mixed>
     */
    private function formatAnswer(string $question, string $answer, array $metrics): array
    {
        return [
            'schemaVersion' => '1.0',
            'question' => $question,
            'answer' => $answer,
            'grounded' => true,
            'referencedMetrics' => $metrics,
        ];
    }

    private function calculateHealthScore(float $nullPercentage, float $duplicateRowPercentage): int
    {
        $score = 100.0;
        // Up to 40 points lost for missingness
        $score -= min(40.0, $nullPercentage * 2.0);
        // Up to 30 points lost for duplicate rows
        $score -= min(30.0, $duplicateRowPercentage * 3.0);

        return max(10, (int) round($score));
    }
}
