# AI Analyst & Narrative Insights (Phase 6)

The AI Analyst is the narrative intelligence layer of DataPulse AI. It interprets and explains the deterministic profiling (Phase 3) and analytics (Phase 5) computed by the Python engine — **without ever calculating metrics itself or seeing raw tabular rows**.

## Core Principles

1. **Deterministic Grounding (Zero Fabrication)**:
   The AI Analyst operates strictly on structured JSON metrics produced by Phase 3 (Profiling) and Phase 5 (Analytics). Every number, percentage, correlation, or outlier count cited in narrative prose or Q&A answers traces back directly to an actual computation.
2. **Strict Insight Categorization (`InsightKind`)**:
   Every generated insight is explicitly classified into one of three distinct categories so the user and UI never confuse facts with conjectures:
   - `computed_fact`: Verifiable mathematical facts directly present in the dataset profile or analytics (e.g. "8.2% missing values in column 'income'", "Strong positive correlation of 0.74 between 'experience' and 'salary'").
   - `statistical_interpretation`: Analytic deductions about distributions, variance, or trends (e.g. "Distribution of 'age' is right-skewed with a long tail beyond 65 years", "Seasonal spike observed every Q4").
   - `recommendation`: Actionable suggestions for data cleaning, further analysis, or business decision-making (e.g. "Apply median imputation to 'income' due to extreme outliers", "Investigate high churn rates in Trial subscription tiers").
3. **Data Privacy & Token Efficiency**:
   Raw tabular records are never sent to the AI provider. Only aggregated schema profiles, distribution bounds, correlation matrices, and time series aggregates are provided as context.
4. **Provider-Agnostic Architecture**:
   The backend relies on `App\Ai\AiClientInterface`. A built-in, fully deterministic `RuleBasedAiClient` guarantees 100% functionality out of the box in test environments, Docker, and offline development without requiring paid third-party API keys, while an LLM adapter can be plugged in seamlessly via configuration.

---

## Architecture & Data Flow

```
Client (Angular)
  │
  │  POST /api/datasets/{id}/versions/{id}/insights          [?force=true]
  │  GET  /api/datasets/{id}/versions/{id}/insights
  │  POST /api/datasets/{id}/versions/{id}/insights/ask      {"question": "..."}
  ▼
Symfony (AiAnalystController)
  │  1. Authenticate + verify ownership + verify version belongs to dataset
  │  2. AiAnalystService:
  │     - Retrieve latest Phase 3 (Profiling) and Phase 5 (Analytics) analyses
  │     - Require both to be completed (422 if not yet profiled/analyzed)
  │     - Check idempotency / cached Analysis (type: ai_insights)
  │  3. AiClientInterface::generateInsights(profile, analytics)
  │     or AiClientInterface::askQuestion(profile, analytics, question)
  ▼
AI Client (RuleBasedAiClient / External LLM)
  │  4. Extract salient patterns:
  │     - Data completeness & health score (0-100)
  │     - Distribution anomalies & outliers
  │     - Significant correlation pairs (|r| >= 0.4)
  │     - Temporal trends & dominant categories
  │  5. Synthesize Executive Summary + Categorized Insights + Suggested Questions
  ▼
Symfony
  │  6. Persist Analysis (type: ai_insights) + Insight entities in database
  │  7. Return response conforming to Schema Version 1.0
  ▼
Angular (AiAnalystSectionComponent)
  │  8. Render Executive Summary, Health Score gauge, filterable Insight badges,
  │     and interactive "Ask the Analyst" query interface
```

---

## JSON Contract (Schema Version `1.0`)

### `GET / POST /api/datasets/{datasetId}/versions/{versionId}/insights`

Response shape on `Analysis.result`:

```json
{
  "schemaVersion": "1.0",
  "datasetVersionId": "version-uuid",
  "healthScore": 88,
  "executiveSummary": "This dataset contains 15,420 records across 6 columns. Overall data quality is high with 99.63% complete cells. Key highlights include a strong positive correlation between annual income and satisfaction score (r = 0.68), while subscription tiers are dominated by Pro (44.3%).",
  "highlights": {
    "totalRows": 15420,
    "totalColumns": 6,
    "completenessPercentage": 99.63,
    "dominantCategory": "subscription_tier: Pro (44.3%)",
    "topCorrelation": "annual_income vs satisfaction_score (r = 0.68)"
  },
  "insights": [
    {
      "id": "uuid",
      "kind": "computed_fact",
      "title": "Strong Correlation Between Income and Satisfaction",
      "description": "Annual income and satisfaction score exhibit a Pearson correlation of 0.68 across 15,236 valid data points.",
      "category": "correlation",
      "metrics": {
        "columns": ["annual_income", "satisfaction_score"],
        "correlation": 0.68,
        "sampleSize": 15236
      }
    },
    {
      "id": "uuid",
      "kind": "statistical_interpretation",
      "title": "Right-Skewed Annual Income Distribution",
      "description": "Mean income ($78,500) significantly exceeds median income ($72,000) with 42 detected high-end outliers up to $240,000.",
      "category": "distribution",
      "metrics": {
        "column": "annual_income",
        "mean": 78500,
        "median": 72000,
        "outlierCount": 42
      }
    },
    {
      "id": "uuid",
      "kind": "recommendation",
      "title": "Address Missing Income Values Before Modeling",
      "description": "184 cells (1.19%) are missing in annual_income. Because of positive skewness, median imputation is recommended over mean imputation.",
      "category": "quality",
      "metrics": {
        "column": "annual_income",
        "missingCount": 184,
        "missingPercentage": 1.19
      }
    }
  ],
  "suggestedQuestions": [
    "What are the strongest relationships between variables?",
    "Are there any notable outliers or data quality issues?",
    "How are categories distributed across the dataset?",
    "What trends appear over time?"
  ]
}
```

### `POST /api/datasets/{datasetId}/versions/{versionId}/insights/ask`

Request body:
```json
{
  "question": "What is the correlation between income and satisfaction?"
}
```

Response body:
```json
{
  "question": "What is the correlation between income and satisfaction?",
  "answer": "Annual income and satisfaction score have a strong positive Pearson correlation of 0.68. Higher income levels generally coincide with higher reported satisfaction.",
  "grounded": true,
  "referencedMetrics": {
    "columns": ["annual_income", "satisfaction_score"],
    "correlation": 0.68
  }
}
```

---

## Health Score Formulation

The health score (0–100) is deterministically calculated from quality indicators:
- **Completeness penalty**: Deducts up to 40 points based on the ratio of missing cells.
- **Duplicate penalty**: Deducts up to 30 points based on the ratio of duplicate rows.
- **Outlier penalty**: Deducts up to 20 points based on the presence of extreme outliers.
- **Formula injection risk penalty**: Deducts 10 points if spreadsheet formula injection risk is flagged.

A dataset with 0 nulls, 0 duplicate rows, and no formula injection risks scores 100/100.
