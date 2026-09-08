<?php

declare(strict_types=1);

namespace App\Cleaning;

/**
 * Turns an already-computed Phase 3 profile (Analysis::$result, a
 * ProfileResult-shaped array) into cleaning recommendations. Never talks
 * to the data engine again — every recommendation is derived purely from
 * numbers the profiling analysis already computed, so nothing here can
 * ever suggest something the data doesn't actually support.
 *
 * A recommendation is not a transformation: it's PROBLEM DETECTED ->
 * RECOMMENDATION. Nothing is ever applied automatically.
 */
final class CleaningRecommendationService
{
    /**
     * @param array<string, mixed> $profile the stored profiling Analysis result
     *
     * @return list<array<string, mixed>>
     */
    public function recommend(array $profile): array
    {
        $recommendations = [];

        $totalRows = (int) ($profile['dataset']['rows'] ?? 0);
        $duplicateRowCount = (int) ($profile['dataset']['duplicateRowCount'] ?? 0);

        if ($duplicateRowCount > 0) {
            $recommendations[] = [
                'type' => 'duplicates',
                'issue' => \sprintf(
                    '%d duplicate row%s (%s%%)',
                    $duplicateRowCount,
                    $duplicateRowCount === 1 ? '' : 's',
                    $profile['dataset']['duplicateRowPercentage'] ?? 0,
                ),
                'recommendedActions' => ['remove_duplicate_rows'],
            ];
        }

        foreach ((array) ($profile['columns'] ?? []) as $column) {
            if (!\is_array($column) || !\is_string($column['name'] ?? null)) {
                continue;
            }

            foreach ($this->recommendationsForColumn($column, $totalRows) as $recommendation) {
                $recommendations[] = $recommendation;
            }
        }

        return $recommendations;
    }

    /**
     * @param array<string, mixed> $column
     *
     * @return list<array<string, mixed>>
     */
    private function recommendationsForColumn(array $column, int $totalRows): array
    {
        $recommendations = [];
        $name = $column['name'];
        $detectedType = $column['detectedType'] ?? 'unknown';
        $nullCount = (int) ($column['nullCount'] ?? 0);
        $uniqueCount = (int) ($column['uniqueCount'] ?? 0);

        if ($nullCount > 0 && $nullCount < $totalRows) {
            $recommendations[] = [
                'type' => 'missing_values',
                'column' => $name,
                'issue' => \sprintf(
                    '%d missing value%s (%s%%)',
                    $nullCount,
                    $nullCount === 1 ? '' : 's',
                    $column['nullPercentage'] ?? 0,
                ),
                'recommendedActions' => $this->missingValueActions($detectedType),
            ];
        }

        // "type_inconsistency": stored as float64 but every value is whole —
        // almost always an artifact of nulls forcing pandas to upcast what
        // is conceptually an integer column. Grounded directly in fields
        // Phase 3 already computed (pandasType vs detectedType).
        if (($column['pandasType'] ?? null) === 'float64' && $detectedType === 'integer') {
            $recommendations[] = [
                'type' => 'type_inconsistency',
                'column' => $name,
                'issue' => 'Stored as a floating-point number but every value is a whole number.',
                'recommendedActions' => ['convert_to_integer'],
            ];
        }

        // Whitespace: only flagged when the profile recorded cells that are
        // *entirely* whitespace — Phase 3's contract does not track general
        // leading/trailing whitespace on otherwise-normal values, so this
        // recommendation is deliberately narrower than "any surrounding
        // whitespace" rather than guessing. See docs/data-cleaning.md.
        $whitespaceOnlyCount = (int) ($column['stringStatistics']['whitespaceOnlyCount'] ?? 0);
        if ($whitespaceOnlyCount > 0) {
            $recommendations[] = [
                'type' => 'whitespace',
                'column' => $name,
                'issue' => \sprintf('%d whitespace-only value%s', $whitespaceOnlyCount, $whitespaceOnlyCount === 1 ? '' : 's'),
                'recommendedActions' => ['trim_whitespace'],
            ];
        }

        $outliers = $column['outliers'] ?? null;
        if (\is_array($outliers) && (int) ($outliers['outlierCount'] ?? 0) > 0) {
            $recommendations[] = [
                'type' => 'outliers',
                'column' => $name,
                'method' => $outliers['method'] ?? 'iqr',
                'outlierCount' => $outliers['outlierCount'],
                'outlierPercentage' => $outliers['outlierPercentage'] ?? 0,
                'recommendedActions' => ['review', 'remove_iqr_outliers'],
            ];
        }

        if ($totalRows > 0 && $nullCount === $totalRows) {
            $recommendations[] = [
                'type' => 'empty_column',
                'column' => $name,
                'issue' => 'Every value in this column is null.',
                'recommendedActions' => ['drop_column'],
            ];
        } elseif ($nullCount === 0 && $uniqueCount === 1 && $totalRows > 1) {
            $recommendations[] = [
                'type' => 'constant_column',
                'column' => $name,
                'issue' => 'Every row has the same value in this column.',
                'recommendedActions' => ['drop_column'],
            ];
        }

        return $recommendations;
    }

    /**
     * @return list<string>
     */
    private function missingValueActions(string $detectedType): array
    {
        $actions = ['drop_rows', 'fill_constant'];

        if (\in_array($detectedType, ['integer', 'float'], true)) {
            $actions[] = 'fill_mean';
            $actions[] = 'fill_median';
        }

        if (\in_array($detectedType, ['categorical', 'boolean'], true)) {
            $actions[] = 'fill_mode';
        }

        return $actions;
    }
}
