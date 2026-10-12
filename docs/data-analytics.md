# Data analytics & visualization (Phase 5)

Turns a `DatasetVersion` into deterministic, chart-ready analytics: descriptive statistics, histograms, box plots, categorical breakdowns, time series, a correlation matrix, and scatter plots. No AI/ML is involved anywhere in this phase — every number is a direct pandas/NumPy computation, and *which* analyses run is decided by fixed, documented rules, not a model.

**Read-only.** Unlike cleaning, analytics never writes a file and never creates a new `DatasetVersion` — it only ever reads the version it's asked about and returns structured JSON.

## Architecture

```
Client
  │  POST /api/datasets/{id}/versions/{id}/analytics   [?force=true]
  │  GET  /api/datasets/{id}/versions/{id}/analytics
  ▼
Symfony (AnalyticsController)
  │  1. authenticate + verify dataset ownership + verify version belongs to dataset
  │  2. AnalyticsService: idempotency check (fingerprint), create/reuse Analysis
  │  3. DataEngineClientInterface::analyze()
  ▼
Data engine (FastAPI) — app/analytics/
  │  4. resolve storageIdentifier against the shared storage volume (same
  │     containment/symlink checks as profiling and cleaning)
  │  5. read into a bounded pandas DataFrame (reuses Phase 3's read_dataframe)
  │  6. select_columns(): deterministically bucket every column as
  │     numeric / categorical / temporal / unanalyzed (reuses Phase 3's
  │     detect_column_type — never re-implemented)
  │  7. compute statistics + chart data per bucket (app/analytics/numeric.py,
  │     categorical.py, temporal.py), plus a correlation matrix and scatter
  │     charts across the numeric columns (correlation.py, scatter.py)
  ▼
Symfony
  8. persist the result on Analysis (type: analytics), return to the client
  ▼
Angular
  9. render the "Dataset Analytics" section: summary, per-column cards with
     Chart.js/SVG charts, correlation matrix, scatter plots
```

**Separation of responsibilities.** `app/analytics/` is its own package — it does not import from `app/cleaning/`, and it only borrows two things from `app/profiling/`: `read_dataframe()` (bounded file I/O) and `detect_column_type()` (type detection). Everything analytics-specific — every statistic, every chart shape, correlation, scatter, granularity selection — is computed fresh in `app/analytics/*.py`, never delegated to profiling's own `column_profiler.py`.

## Automatic analysis selection

`app/analytics/selection.py::select_columns()` buckets every column using Phase 3's existing `detect_column_type()` result — no new heuristic, no AI:

| Detected type | Bucket | Gets |
|---|---|---|
| `integer`, `float` | numeric | statistics, histogram, boxplot |
| `categorical`, `boolean` | categorical | statistics, bar chart |
| `date`, `datetime` | temporal | statistics, time series |
| `string`, `unknown` | unanalyzed | nothing (counted in `summary.unanalyzedColumnCount`) |

Correlations and scatter plots are derived automatically from the numeric bucket (see below) — there is no separate "should I compute a correlation matrix" heuristic beyond "are there at least two numeric columns".

This selection is **fully deterministic**: the same file always produces the same bucketing, the same charts, and the same numbers — verified by `tests/test_analytics.py::test_analytics_result_is_deterministic_across_runs`.

## Numeric analytics

Per numeric column: `count`, `missingCount`, `missingPercentage`, `uniqueCount`, `min`, `max`, `mean`, `median`, `standardDeviation`, `variance`, `q1`, `q3`, `iqr`, `sum`, `range`.

**Histogram.** `{"type": "histogram", "column": "...", "bins": [...], "counts": [...]}` — `bins` has `counts.length + 1` edges. Bin count is `min(histogram_max_bins, max(1, uniqueCount))`, so a low-cardinality column (e.g. integers 1–5) never gets padded with empty bins, and no column ever exceeds `histogram_max_bins` (default 20). A constant column (`min == max`) gets a valid single-bin histogram instead of being omitted.

**Box plot.** `{"type": "boxplot", "column": "...", "min", "q1", "median", "q3", "max", "lowerFence", "upperFence", "outlierCount"}`. `min`/`max` are the column's actual data extremes; `lowerFence`/`upperFence` are the standard `q1 - 1.5·iqr` / `q3 + 1.5·iqr` Tukey fences used to flag `outlierCount`. When `iqr == 0` (heavily repeated values), `outlierCount` is forced to `0` rather than flagging every non-identical value — the same policy Phase 3's own outlier detection already uses, kept consistent rather than reinvented.

## Categorical analytics (and boolean)

Per categorical/boolean column: `count`, `missingCount`, `missingPercentage`, `uniqueCount`, `topValues` (up to `top_values_limit`, reusing the exact same setting Phase 3 uses for its own top-values list — one knob, not two).

**Bar chart.** `{"type": "bar", "column": "...", "labels": [...], "values": [...]}` — always at most `top_values_limit + 1` entries (the extra one being a synthetic `"Other"` bucket summing everything past the top N). This bound holds regardless of how many distinct values the column actually has — a column with 50,000 distinct IDs still produces an 11-entry bar chart, never a multi-thousand-entry JSON blob. In practice this rarely triggers, because Phase 3's own categorical-detection ratio (`unique/total ≤ 0.5`, `unique ≤ 50`) already routes genuinely high-cardinality columns into "unanalyzed" before analytics ever sees them as categorical.

## Temporal analytics

Per date/datetime column: `count`, `missingCount`, `missingPercentage`, `minDate`, `maxDate` (ISO 8601).

**Time series + granularity.** `{"type": "time_series", "column": "...", "granularity": "day"|"week"|"month"|"quarter"|"year", "x": [...], "y": [...]}`. Granularity is chosen purely from the column's date span (`app/analytics/temporal.py::_choose_granularity`), never from row count or any other heuristic that could misrepresent the spread of dates:

| Span | Granularity |
|---|---|
| ≤ 62 days | day |
| ≤ 210 days | week |
| ≤ 900 days | month |
| ≤ 2600 days | quarter |
| wider | year |

These thresholds are chosen to keep the resulting bucket count in a readable range (roughly a handful to a few dozen buckets) without collapsing a short span into one misleading bucket. `x` labels are the ISO start-date of each bucket (not pandas' own `Period` string, which is noisy for weekly buckets — e.g. `"2025-12-29/2026-01-04"`). A column with as little as one valid date still produces a valid (single-point) chart; a column with zero valid dates gets `chart: null`.

## Correlation matrix

Computed with **Pearson correlation** (`pandas.DataFrame.corr(method="pearson")`) across up to `max_correlation_columns` (default 15) numeric columns, in original column order — a wider dataset is capped rather than producing an unbounded O(n²) matrix. Requires at least 2 numeric columns; otherwise `correlations: null`.

```json
{ "type": "correlation_matrix", "method": "pearson", "columns": [...], "matrix": [[...]] }
```

`corr()` already computes each pair over its own pairwise-complete rows (dropping only the rows where *that specific pair* has a missing value) and returns `NaN` for a constant column (zero variance) automatically. Both cases — and any other NaN/Infinity — are normalized to JSON `null` via the same `_safe_float` discipline used everywhere else in this project; **the API never emits a NaN or Infinity literal**, verified by `tests/test_analytics.py::test_analytics_result_serializes_without_nan_or_infinity` and the equivalent endpoint test.

## Scatter plots

Pairs are picked automatically from the correlation matrix — ranked by `|correlation|` descending (a pair with an undefined/null correlation always sorts last), ties broken by column order, top `max_scatter_pairs` (default 5) kept:

```json
{ "type": "scatter", "xColumn": "...", "yColumn": "...", "correlation": 0.87, "points": [{"x":..,"y":..}], "totalPoints": 1000, "sampled": true, "sampleSize": 500 }
```

**Point sampling is for chart legibility only, never a data transformation.** When a pair has more than `max_scatter_points` (default 500) valid points, a fixed-seed sample (`random_state=42`) is drawn — deterministic and reproducible, never presented as anything other than what it is: `sampled: true` plus the real `totalPoints` are always included alongside the (possibly sampled) `points` array, so a consumer can never mistake the chart's point count for the dataset's actual row count.

## The JSON contract

`schemaVersion: "1.0"`. Top-level shape:

```json
{
  "schemaVersion": "1.0",
  "dataset": { "rows": ..., "columns": ..., "totalCells": ..., "totalNullCells": ..., "nullPercentage": ..., "duplicateRowCount": ..., "duplicateRowPercentage": ... },
  "summary": { "numericColumnCount": ..., "categoricalColumnCount": ..., "temporalColumnCount": ..., "unanalyzedColumnCount": ..., "chartCount": ... },
  "numeric": [ { "column": "...", "statistics": {...}, "histogram": {...}, "boxplot": {...} } ],
  "categorical": [ { "column": "...", "statistics": {...}, "chart": {...} } ],
  "temporal": [ { "column": "...", "statistics": {...}, "chart": {...} } ],
  "correlations": { "type": "correlation_matrix", "method": "pearson", "columns": [...], "matrix": [[...]] },
  "scatter": [ {...} ],
  "charts": [ "...every histogram/boxplot/bar/time_series/scatter chart above, flattened into one list, each tagged by its own \"type\"..." ]
}
```

`dataset` is the same minimal shape Phase 3's profiling contract already uses (`DatasetSummary`, imported and reused directly — a shared data shape, not shared business logic). `summary` is analytics' own meta-summary of *what got analyzed*, letting the frontend answer "are there any numeric columns at all?" without counting array lengths itself. `charts` is a flattened convenience array (every chart object already appears nested under `numeric[]`/`categorical[]`/`temporal[]`/`scatter[]` too) for a consumer that wants to iterate "every chart" generically by its `type` discriminator; the current Angular frontend renders from the typed arrays directly and doesn't consume `charts` separately.

Bump `schemaVersion` (in `app/config.py`'s `analytics_schema_version`, kept in lockstep with `AnalyticsService::SCHEMA_VERSION` on the Symfony side) whenever a field is added, removed, or changes meaning.

## Symfony orchestration & persistence

Reuses the exact same shape Phase 3/4 already established — no parallel structure:

- **`AnalysisType::Analytics = 'analytics'`** — one more case on the existing plain-string enum (`Analysis::$type` was deliberately never DB-mapped, precisely so a new analysis kind needs no migration).
- **`Analysis`** — reused as-is. `markRunning()` / `markCompleted()` / `markFailed()` are already fully generic. The fingerprint is stored via the same `setPipeline(array $configuration, string $fingerprint)` method cleaning uses (analytics currently has no user-configurable parameters, so it's called with an empty array — see Idempotence below); no entity change was needed.
- **`AnalyticsService`** (`backend/src/Service/AnalyticsService.php`) — mirrors `DatasetProfilingService`'s control flow (idempotency check → create `Analysis` → call the data engine → `markCompleted`/`markFailed`) with `CleaningService`'s fingerprint-based idempotency.
- **`AnalyticsController`** — two routes, identical shape to `AnalysisController`'s profiling routes, `AnalysisPresenter::present()` reused verbatim (it was already fully generic — no bespoke presenter needed, unlike cleaning's `CleaningResultPresenter`).
- **`AnalysisRepository::findByFingerprint(DatasetVersion, string $type, string $fingerprint)`** — generalized from cleaning's `findByPipelineFingerprint()` (which hardcoded `AnalysisType::Cleaning`) into a `$type`-parameterized method both cleaning and analytics now call.
- **`DataEngineClientInterface::analyze()`** — one more method alongside `profile()`/`clean()`/`previewClean()` on the single shared client interface.

## Idempotence

```
fingerprint = sha256(sourceVersion.checksum + "|" + schemaVersion)
```

There is no client-supplied configuration yet — column selection is fully automatic — so the schema version alone stands in for "the exact configuration this analysis was run with". A future version that adds selectable parameters (e.g. explicit column choice) would fold their canonical JSON into this same fingerprint, exactly as `PipelineParser::fingerprint()` does for cleaning's operation list.

By default, re-requesting analytics for a version whose *completed* analysis already has a matching fingerprint reuses it instead of recomputing anything (verified: `AnalyticsControllerTest::testIdempotentByDefaultReusesCompletedAnalysisForSameVersion`, asserting the data engine is called exactly once across two requests). `?force=true` always starts a fresh run (`AnalyticsControllerTest::testForceTrueAlwaysTriggersAFreshRun`). A prior `pending`/`running`/`failed` analysis is never reused, same policy as profiling and cleaning.

No cross-request locking — same documented limitation as profiling and cleaning.

## API

```
POST /api/datasets/{datasetId}/versions/{versionId}/analytics   [?force=true]
GET  /api/datasets/{datasetId}/versions/{versionId}/analytics     (latest analytics Analysis, without triggering; 404 if none yet)
```

Response shape is the project-wide generic `Analysis` presentation (`id`, `datasetVersionId`, `type`, `status`, `resultSchemaVersion`, `result`, `errorMessage`, `createdAt`/`startedAt`/`completedAt`) — `result` holds the contract above once `status: "completed"`.

**Errors** follow the project-wide `{"status": "error", "message": "..."}` shape:

| Status | Meaning |
|---|---|
| 401 | Unauthenticated |
| 403 | Dataset exists but isn't yours |
| 404 | Dataset/version doesn't exist, version doesn't belong to that dataset, or (GET) no analytics run yet |
| 413 | Source file too large / too many rows / too many columns to load (same limits as profiling) |
| 500 | Unexpected internal error (never with a stack trace or file path in the message) |

## Limits

Reuses Phase 3's exact source-file limits (`max_profilable_bytes`, `max_rows`, `max_columns`) — same "reject outright rather than silently truncate" policy. Analytics-specific bounds, all in `data-engine/app/config.py`:

| Setting | Default | Bounds |
|---|---|---|
| `histogram_max_bins` | 20 | Histogram bin count per numeric column |
| `top_values_limit` (reused from profiling) | 10 | Bar chart categories per categorical column, plus one "Other" |
| `max_correlation_columns` | 15 | Numeric columns included in the correlation matrix |
| `max_scatter_pairs` | 5 | Scatter charts produced |
| `max_scatter_points` | 500 | Points drawn per scatter chart (sampled beyond this, see above) |

None of these caps ever silently drop or misrepresent *analyzed* data — they only ever bound how much of an already-computed result gets serialized into the response (e.g. "Other" still reports the true summed count; scatter always reports the true `totalPoints` alongside a sampled `points` array).

## Security

Every Phase 1–4 guarantee still holds unchanged: JWT authentication required, dataset **and** version ownership checked before any work starts, storage identifiers re-validated for path traversal/symlinks independently on both sides (`DatasetStorageResolver`, unchanged), size limits enforced, no secrets or dataset content logged (only identifiers/metadata).

**The client only ever sends a `datasetVersionId` (via the URL) — never a path.** Analytics is read-only against the version's already-recorded `storagePath`; there is no field anywhere in the analytics request a client could use to point at an arbitrary file. The analytics result itself never includes the physical storage path or any filesystem detail — only column names, statistics, and chart data drawn from the dataset's own content.

## Chart library choice

**Chart.js** (core library, no framework wrapper) is the one charting dependency this phase adds. It was chosen over Angular-specific wrappers (`ngx-charts`, `ng2-charts`) because Angular 22 is new enough that no wrapper library had a confirmed-compatible release at the time of writing, while Chart.js itself has zero Angular peer dependency — it draws to a plain `<canvas>`, so it can never fall out of sync with the host framework's version. A single reusable `app-chart-canvas` component (`frontend/src/app/shared/chart-canvas.component.ts`) wraps the Chart.js instance lifecycle (create on config change, destroy on cleanup, via Angular's `effect(onCleanup)`); histograms, bar charts, time series, and scatter plots are all just different `ChartConfiguration` objects built by `frontend/src/app/features/datasets/analytics-chart-builder.ts` and passed to that one component — no per-chart-type Angular component was needed.

Two visualizations deliberately use **no charting library at all**:
- **Box plot** — a fixed set of 7 numbers (min/q1/median/q3/max/fences) and an outlier count; simple enough to render as plain SVG (`app-boxplot-chart`) rather than pull in a second dependency (Chart.js core has no box-plot support without a third-party plugin).
- **Correlation matrix** — just a table with colored cells (`app-correlation-matrix`), no charting semantics involved.

The backend never produces an image — every chart payload above is plain chart-ready JSON (bins/counts, labels/values, x/y series, points); Angular alone is responsible for rendering.

**Testing note:** the frontend's automated tests run under `vitest`/`jsdom`, which has no real `<canvas>` 2D rendering backend — Chart.js logs a harmless `Not implemented: HTMLCanvasElement's getContext()` warning during those tests. This verifies the chart *data wiring* (the right `ChartConfiguration` reaches the right place) but not actual rendered pixels; real rendering was confirmed by manual browser verification (see the Phase 5 final report).

## PHPStan

Introduced this phase at **level 5** (`backend/phpstan.dist.neon`), with the `phpstan-symfony` and `phpstan-doctrine` extensions enabled. Level 5 was chosen deliberately over a stricter level: it catches real bugs (wrong argument/return types, dead code, unhandled match arms) without demanding full generic-array typing (levels 6–9) retrofitted across four phases of already-shipped, tested business logic in one pass. Run it with:

```bash
DATABASE_URL=<a reachable Postgres DSN — only used to read Doctrine mapping metadata, never queried>
php vendor/bin/phpstan analyse
```

The first real run found and fixed three genuine bugs (not suppressed — every finding was fixed at its root cause):
1. `AuthController`/`CleaningController` caught the wrong `JsonException` class (`\JsonException`, the global PHP one) around `Request::toArray()`, which actually throws `Symfony\Component\HttpFoundation\Exception\JsonException` — a different, non-related class. A malformed JSON request body was silently *not* caught, producing a generic 500 instead of the intended clean `400 Invalid JSON body.`. Both fixed to catch the correct class; regression tests added (`testRegisterRejectsMalformedJsonBodyWith400`, `testMalformedJsonBodyReturns400`).
2. `HttpDataEngineClient::request()` had two `is_array()` guards on a value whose type (`HttpClientInterface::toArray()`'s return type) is already guaranteed `array` — genuinely dead code, removed along with the now-unreachable `DataEngineException::unexpectedResponse()` factory it was the only caller of.
3. `FilenameSanitizer::extractExtension()` was missing a precise return type (`'csv'|'xlsx'|'json'`), even though its own runtime check already guarantees it — added the PHPDoc annotation rather than a redundant runtime branch, fixing a `match.unhandled` warning in `DatasetIngestionService` at the actual source of the imprecision.

No baseline file and no `ignoreErrors` entries — the config runs fully clean.

## ESLint

Introduced this phase via `angular-eslint` (flat config, `frontend/eslint.config.js`), version-matched to Angular 22. See the final report for the exact configuration and findings — deliberately not a full stylistic rewrite of four phases of existing frontend code, only real TypeScript/Angular-practice issues.
