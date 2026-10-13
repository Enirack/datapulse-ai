<?php

declare(strict_types=1);

namespace App\Tests\Ai;

use App\Ai\RuleBasedAiClient;
use PHPUnit\Framework\TestCase;

final class RuleBasedAiClientTest extends TestCase
{
    private RuleBasedAiClient $client;

    protected function setUp(): void
    {
        $this->client = new RuleBasedAiClient();
    }

    public function testGenerateInsightsProducesCompleteAnalysis(): void
    {
        $profile = [
            'rowCount' => 1200,
            'columnCount' => 6,
            'duplicateRows' => 24,
            'duplicateRowsRatio' => 0.02,
            'columns' => [
                'id' => ['type' => 'integer', 'nullRatio' => 0.0],
                'age' => ['type' => 'integer', 'nullRatio' => 0.05],
                'email' => ['type' => 'email', 'nullRatio' => 0.0],
                'churn' => ['type' => 'boolean', 'nullRatio' => 0.0],
            ],
        ];

        $analytics = [
            'summary' => [
                'numericColumnCount' => 2,
                'categoricalColumnCount' => 2,
                'temporalColumnCount' => 0,
            ],
            'numeric' => [
                [
                    'column' => 'age',
                    'mean' => 38.5,
                    'median' => 36.0,
                    'stdDev' => 12.2,
                    'min' => 18.0,
                    'max' => 95.0,
                    'iqr' => 18.0,
                    'skewness' => 0.85,
                    'outliers' => [
                        'count' => 14,
                        'percentage' => 1.17,
                    ],
                ],
            ],
            'correlations' => [
                'columns' => ['age', 'churn'],
                'matrix' => [
                    [1.0, 0.62],
                    [0.62, 1.0],
                ],
                'strongestPairs' => [
                    [
                        'columnA' => 'age',
                        'columnB' => 'churn',
                        'correlation' => 0.62,
                        'direction' => 'positive',
                    ],
                ],
            ],
        ];

        $result = $this->client->generateInsights($profile, $analytics);

        $this->assertSame('1.0', $result['schemaVersion']);
        $this->assertGreaterThanOrEqual(0, $result['healthScore']);
        $this->assertLessThanOrEqual(100, $result['healthScore']);
        $this->assertNotEmpty($result['executiveSummary']);
        $this->assertNotEmpty($result['insights']);

        $kinds = array_unique(array_column($result['insights'], 'kind'));
        $this->assertContains('computed_fact', $kinds);
        $this->assertContains('recommendation', $kinds);
    }

    public function testAskQuestionProvidesGroundedAnswer(): void
    {
        $profile = [
            'rowCount' => 500,
            'columnCount' => 3,
            'duplicateRows' => 5,
            'duplicateRowsRatio' => 0.01,
            'columns' => [
                'price' => ['type' => 'float', 'nullRatio' => 0.12],
            ],
        ];

        $analytics = [
            'summary' => [
                'numericColumnCount' => 1,
                'categoricalColumnCount' => 0,
                'temporalColumnCount' => 0,
            ],
            'numeric' => [
                [
                    'column' => 'price',
                    'mean' => 49.99,
                    'outliers' => ['count' => 8, 'percentage' => 1.6],
                ],
            ],
        ];

        // Question about missing values
        $response1 = $this->client->askQuestion($profile, $analytics, 'Are there any missing values or null cells?');
        $this->assertSame('1.0', $response1['schemaVersion']);
        $this->assertStringContainsString('missing', strtolower((string) $response1['answer']));
        $this->assertNotEmpty($response1['referencedMetrics']);

        // Question about outliers
        $response2 = $this->client->askQuestion($profile, $analytics, 'What about outliers in price?');
        $this->assertStringContainsString('price', (string) $response2['answer']);
        $this->assertStringContainsString('8', (string) $response2['answer']);

        // Question about dataset size
        $response3 = $this->client->askQuestion($profile, $analytics, 'How many rows are in the dataset?');
        $this->assertStringContainsString('500', (string) $response3['answer']);
    }
}
