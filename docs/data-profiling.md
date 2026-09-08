# Data profiling (Phase 3)

Turns an already-uploaded, already-validated `DatasetVersion` into a structured statistical profile. Symfony orchestrates and persists; the Python data engine does the actual reading and computing.

## Architecture

```
Client
  │  POST /api/datasets/{datasetId}/versions/{versionId}/profile
  ▼
Symfony (AnalysisController)
  │  1. authenticate + verify dataset ownership + verify version belongs to dataset
  │  2. DatasetProfilingService: idempotency check, create/reuse Analysis
  │  3. HttpDataEngineClient: POST http://data-engine:8001/internal/profile
  │       body: { datasetVersionId, storageIdentifier, extension }
  ▼
Data engine (FastAPI)
  │  4. resolve storageIdentifier against the *shared* storage volume
  │     (no file bytes cross the wire — see "Storage sharing" below)
  │  5. read into a bounded pandas DataFrame
  │  6. detect column types, compute statistics
  ▼
Symfony
  6. persist the result on the Analysis row (status, result JSON, timestamps)
  7. return the Analysis to the client
```

Nothing here calls the profiling logic in-process from Symfony — PHP and Python stay in their own processes/containers, communicating only over the `POST /internal/profile` HTTP contract below.

## Storage sharing

Symfony and the data engine mount the **same** Docker named volume (`backend_storage`) — read-write for the backend (it's the only writer), read-only for the data engine. Symfony sends only the dataset version's opaque storage identifier (e.g. `ab/less-some-uuid.csv`), never the file itself:

- No large file transfer over HTTP between the two services.
- The data engine independently re-validates the identifier the same way the backend's own storage class does — rejecting `..`, null bytes, and anything that resolves outside its configured storage root — even though the identifier always comes from a trusted caller (defense in depth, not because the backend is untrusted).
- The data engine never receives, needs, or has a database connection. It is a stateless computation service.

## `POST /internal/profile` (data engine → internal only)

Never exposed outside the Docker network (no host port mapping needed for this route to work end-to-end — it's still published on `DATA_ENGINE_PORT` for local debugging).

**Request**
```json
{
  "datasetVersionId": "01a08073-b902-725d-9cf5-e7d8b01c68b1",
  "storageIdentifier": "80/80d770dc-437c-4136-975b-4aba5f2923ad.csv",
  "extension": "csv"
}
```

**Responses**

| Status | Meaning |
|---|---|
| 200 | Profile computed — body is the [result contract](#result-contract) |
| 400 | Malformed request body (missing/invalid fields) |
| 404 | `storageIdentifier` doesn't resolve to a real file |
| 413 | File exceeds `DATA_ENGINE_MAX_PROFILABLE_BYTES`, or has more than `DATA_ENGINE_MAX_ROWS` rows / `DATA_ENGINE_MAX_COLUMNS` columns once read |
| 422 | Content isn't profilable (malformed CSV/XLSX, or JSON that isn't tabular-convertible — see below) |
| 500 | Unexpected internal error |

Every error response is `{"status": "error", "message": "..."}` — the same shape used everywhere else in the API. No stack trace, path, or internal detail is ever included.

## `POST /api/datasets/{datasetId}/versions/{versionId}/profile` (public API)

Requires a valid JWT. `404` if the dataset id doesn't exist at all; `403` if it exists but isn't yours; `404` if the version id doesn't belong to that dataset (even if the version id is real, just under a different dataset). On success, always `201` — **the analysis's own `status` field carries the real outcome, not the HTTP status**: a profiling failure (e.g. unparseable content) still returns `201` with `status: "failed"` and `errorMessage` set, because the *request to run an analysis* succeeded even when the *analysis* didn't.

Query param `?force=true` bypasses idempotency (see below) and always starts a fresh analysis.

`GET` on the same path returns the latest analysis without triggering a new one — `404` if none exists yet.

## Idempotency

Calling `POST .../profile` when a **completed** analysis already exists for that version returns that existing `Analysis` unchanged — the data engine is not called again. A prior `pending`/`running`/`failed` analysis is never reused; the request always starts a fresh one in that case. `?force=true` always starts fresh regardless of any existing result.

There is no cross-request locking — this phase has no job queue. Two concurrent requests for a version with no prior analysis can both start a run. Documented as a known limitation rather than solved with infrastructure this phase doesn't otherwise need.

## Result contract

`schemaVersion: "1.0"`. Bump this string (and document the change here) whenever the shape changes in a way that could break a consumer.

```json
{
  "schemaVersion": "1.0",
  "dataset": {
    "rows": 1000,
    "columns": 12,
    "totalCells": 12000,
    "totalNullCells": 143,
    "nullPercentage": 1.19,
    "duplicateRowCount": 4,
    "duplicateRowPercentage": 0.4
  },
  "columns": [
    {
      "name": "age",
      "pandasType": "float64",
      "detectedType": "integer",
      "nullable": true,
      "nullCount": 12,
      "nullPercentage": 1.2,
      "uniqueCount": 78,
      "uniquePercentage": 7.8,
      "duplicateCount": 922,
      "statistics": { "min": 18, "max": 74, "mean": 36.42, "median": 35, "std": 10.21, "variance": 104.25, "q25": 27, "q50": 35, "q75": 45 },
      "stringStatistics": null,
      "categorical": null,
      "outliers": { "method": "iqr", "outlierCount": 3, "outlierPercentage": 0.3 }
    }
  ]
}
```

Every field is always present (never omitted); `statistics`/`stringStatistics`/`categorical`/`outliers` are `null` when not applicable to that column's `detectedType`, never omitted. **No field is ever `NaN` or `Infinity`** — unrepresentable-in-JSON values are always `null` (see "NaN/Infinity handling" below).

### `duplicateCount`, precisely

`duplicateCount = totalRows - uniqueCount` (where `uniqueCount` is computed over non-null values only). This means null cells are folded into the same bucket as "not a first-seen value" rather than tracked completely separately from duplicates — a deliberate, simple, single definition applied consistently everywhere, not a bug.

### Business types

| `detectedType` | Detected from |
|---|---|
| `integer` | Native pandas integer dtype, **or** a float64 column whose non-null values are all mathematically whole (common when nulls force pandas to use float64 for a conceptually-integer CSV column) |
| `float` | Numeric dtype that isn't all-whole |
| `boolean` | Native pandas bool dtype only — `"yes"`/`"no"`/`"1"`/`"0"` strings are **never** coerced to boolean, too ambiguous |
| `date` / `datetime` | Object column whose sampled values match one of a small, explicit, documented set of regex patterns (ISO `YYYY-MM-DD`, `DD/MM/YYYY`, ISO 8601 datetime) at a ≥90% hit rate on a sample — see `app/profiling/type_detection.py` for the exact patterns. Deliberately does **not** use pandas' own permissive date inference directly on arbitrary strings. `DD/MM/YYYY` is inherently day/month-ambiguous; documented, not resolved. |
| `categorical` | Object column with ≤50 unique values **and** a unique-to-row-count ratio ≤0.5 |
| `string` | Object column that doesn't qualify as categorical or date-like |
| `unknown` | Entirely-null column — there's nothing to detect from |

CSV is assumed to have a header row (pandas' default `header=0`) — Phase 2's best-effort CSV header heuristic is not reused here; this is a simpler, explicit, documented assumption for this phase.

### CSV delimiter detection

Same conservative approach as Phase 2's `CsvValidator` (not reused code — reused *technique*, since it lives in a different language runtime): try `, ; \t |`, sample the first lines, keep whichever delimiter consistently produces more than one column; default to `,` (i.e. effectively single-column) otherwise. pandas' own `sep=None` sniffer was tried first and rejected — it reliably misfires on single-column files (it can pick a letter that happens to appear in the data as the "delimiter").

### JSON → tabular conversion

- A top-level array of objects → used directly as rows.
- A top-level array of scalars → wrapped as single-column `{"value": ...}` rows.
- An object with **exactly one** top-level array property → that array is used (the common `{"data": [...]}` wrapper shape).
- Anything else (a bare scalar, an object without exactly one qualifying array property, deeply nested non-tabular structures) → `422`. This phase does not attempt to flatten arbitrary JSON.

An empty JSON array (`[]`) is the only way an "empty dataset" reaches profiling — Phase 2 already rejects empty CSV/XLSX files at upload time, so those can never arrive here empty.

### NaN/Infinity handling

Every numeric statistic passes through a `_safe_float()` helper that returns `null` for `NaN`/`Infinity`/`-Infinity` before it can reach the JSON response. Concretely:
- Infinite values in the source data (e.g. a literal `inf` in a CSV cell) are excluded from min/max/mean/etc. computation entirely, not just from the final output.
- A single-value or all-identical-value column has `std`/`variance` of `0.0` (a real, valid statistic — not `null`).
- A column with fewer than 2 non-null values has `std`/`variance` of `null` (mathematically undefined at n<2, not `NaN`).

### Categorical top values

Capped at `top_values_limit` (10 by default) regardless of how many distinct values the column actually has — `truncated: true` signals more exist. Each value string is itself capped at 200 characters. This is what stops a high-cardinality column from blowing up the response size.

### Outliers (IQR method)

Computed for numeric columns with ≥4 non-null values. `lower = Q1 - 1.5×IQR`, `upper = Q3 + 1.5×IQR`; anything outside that range counts as an outlier. If `IQR == 0` (no spread — e.g. a constant column), `outlierCount` is `0` rather than flagging everything or dividing by zero. Never removes or alters data — purely reported.

## Size and row/column limits

| Limit | Env var | Default | Behavior at limit |
|---|---|---|---|
| File size on disk | `DATA_ENGINE_MAX_PROFILABLE_BYTES` | 25 MiB | `413`, checked before any parsing |
| Rows | `DATA_ENGINE_MAX_ROWS` | 200,000 | `413` if the file has more |
| Columns | `DATA_ENGINE_MAX_COLUMNS` | 500 | `413` if the file has more |

Deliberately **not** chunked/streamed profiling: a file over the limit is rejected outright rather than silently profiled on a truncated slice. A partial profile presented as if it covered the whole dataset would misrepresent the result — the same "never fabricate" principle the rest of this project applies to computed numbers, applied here to *how much of the data* a number is based on. `DATA_ENGINE_MAX_PROFILABLE_BYTES` is intentionally independent from (and expected to stay ≤) the backend's `MAX_UPLOAD_SIZE` — a file can be small enough to accept but still too heavy to comfortably load into memory and profile.

## Security

- The data engine never trusts a filename — it only ever receives a storage identifier the backend generated, and still independently re-validates it (path containment, symlink rejection) exactly like the PHP storage class does.
- The data engine has no database access and no route reachable from the browser.
- Ownership is checked before any profiling work starts, at the Symfony layer — a user can never trigger or read profiling for another user's dataset (`403`)/version (`404` if it belongs to someone else's dataset).
- No dataset content, JWT tokens, or passwords are ever logged — only identifiers, sizes, durations, and status (see `docs/development.md` for what a log line looks like).

## Persistence

`Analysis` (extended in this phase): `id`, `datasetVersion`, `type` (`"profiling"`), `status` (`pending → running → completed | failed`), `resultSchemaVersion`, `result` (JSON, the contract above), `errorMessage`, `createdAt`, `startedAt`, `completedAt`. `result`/`errorMessage` are mutually exclusive in practice (only one is ever set, matching whichever terminal status was reached).
