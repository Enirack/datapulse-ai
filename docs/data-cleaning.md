# Data cleaning & transformation (Phase 4)

Turns a Phase 3 profile into actionable recommendations, lets the user pick which transformations to apply, and applies them deterministically — producing a **new** `DatasetVersion` and never touching the one it started from.

**Original dataset = immutable.** No cleaning operation ever modifies an existing file or `DatasetVersion` row. A successful, data-changing run always creates a new `DatasetVersion` (`kind: cleaned`) pointing back at its source; a run that changes nothing completes without creating one.

## Architecture

```
Client
  │  GET  /api/datasets/{id}/versions/{id}/cleaning-recommendations
  │  POST /api/datasets/{id}/versions/{id}/clean/preview
  │  POST /api/datasets/{id}/versions/{id}/clean
  ▼
Symfony (CleaningController)
  │  1. authenticate + verify dataset ownership + verify version belongs to dataset
  │  2. PipelineParser: structural validation (known op types, required params) -> 400
  │  3. CleaningRecommendationService: derives recommendations from the *already
  │     computed* Phase 3 profile — never calls the data engine again for this
  │  4. CleaningService: idempotency check, create/reuse Analysis
  │  5. DataEngineClientInterface::clean()/previewClean()
  ▼
Data engine (FastAPI) — app/cleaning/
  │  6. resolve storageIdentifier against the shared storage volume (same
  │     containment/symlink checks as profiling)
  │  7. read into a bounded pandas DataFrame (reuses Phase 3's read_dataframe)
  │  8. run the pipeline, one operation at a time, in the given order
  │  9. compare the resulting DataFrame to the source (DataFrame.equals) —
  │     "changed" is a data comparison, never a byte comparison
  │ 10. if changed: serialize, checksum, write to the shared tmp handoff area
  ▼
Symfony
 11. if changed: commit the tmp file into permanent storage via
     DatasetStorageInterface::store(), create the new DatasetVersion
     (kind: cleaned, sourceVersion: the version that was cleaned)
 12. persist the result on Analysis (type: cleaning), return to the client
```

Recommendations are deliberately **not** a data-engine round trip: they're derived purely from numbers Phase 3 already computed and stored in the profiling `Analysis.result`. This means a recommendation can never suggest something the actual data doesn't support — there is nothing to fabricate because nothing new is being read.

## Storage sharing

Symfony and the data engine share the `backend_storage` Docker volume, read-write for both now (Phase 3 had it read-only for the data engine). The scoping is by **convention, not by mount permission**:

- The data engine only ever *reads* under `storage/datasets/` (the backend's permanent files).
- The data engine only ever *writes* under `storage/tmp/` — a cleaning result, never a permanent file.
- The backend is the only thing that ever writes into `storage/datasets/`, via `DatasetStorageInterface::store()` — exactly as in Phase 2. Committing a cleaned file means moving (`rename()`, atomic on the same volume) it from `storage/tmp/` into that permanent location; nothing ever writes there directly.

`DATASET_TMP_DIR` (Symfony) / `DATA_ENGINE_TMP_DIR` (data engine) point at the same physical subtree, the same way `DATASET_STORAGE_DIR` / `DATA_ENGINE_STORAGE_DIR` already did for permanent files in Phase 3.

## Recommendation engine

`App\Cleaning\CleaningRecommendationService` inspects a stored `ProfileResult` and emits one recommendation per detected issue:

| `type` | Triggered by | `recommendedActions` |
|---|---|---|
| `duplicates` | `dataset.duplicateRowCount > 0` | `remove_duplicate_rows` |
| `missing_values` | a column's `nullCount > 0` | `drop_rows`, `fill_constant`, plus `fill_mean`/`fill_median` only for numeric columns, plus `fill_mode` only for categorical/boolean columns |
| `type_inconsistency` | `pandasType == "float64"` but `detectedType == "integer"` (nulls forced pandas to upcast a conceptually-integer column) | `convert_to_integer` |
| `whitespace` | a column's `stringStatistics.whitespaceOnlyCount > 0` | `trim_whitespace` |
| `outliers` | a column's `outliers.outlierCount > 0` (IQR, from Phase 3) | `review`, `remove_iqr_outliers` |
| `empty_column` | every value in a column is null | `drop_column` |
| `constant_column` | every non-null row has the same value | `drop_column` |

**Known limitation — whitespace detection is narrower than "any surrounding whitespace".** Phase 3's contract only tracks `whitespaceOnlyCount` (cells that are *entirely* whitespace), not a general "has leading/trailing whitespace" count. A value like `" Paris"` next to otherwise-clean values won't trigger a `whitespace` recommendation on its own — only a cell that's *nothing but* whitespace will. This is a deliberate, documented boundary rather than a guess: extending it would mean going back and changing the already-shipped Phase 3 profiling contract, which isn't done lightly (see `docs/data-profiling.md`).

**A recommendation is not a transformation.** Nothing is ever applied automatically:

```
PROBLEM DETECTED  →  RECOMMENDATION  →  USER DECISION  →  TRANSFORMATION
```

## Transformations

| `type` | Params | Applies to | Notes |
|---|---|---|---|
| `remove_duplicate_rows` | — | any | Keeps the first occurrence of each duplicate row. |
| `trim_whitespace` | `column` | string-like | Leading/trailing whitespace only. |
| `normalize_internal_whitespace` | `column` | string-like | Collapses runs of internal spaces/tabs to one; does **not** also trim outer whitespace — compose both if you want both. |
| `drop_rows` | `column` | any | Drops rows where `column` is null. |
| `fill_constant` | `column`, `value` | any | Rejects a non-numeric `value` for a numeric column. |
| `fill_mean` / `fill_median` | `column` | numeric only | 422 if the column isn't numeric. |
| `fill_mode` | `column` | any | First mode by pandas' stable ordering if there's a tie. |
| `convert_to_integer` / `convert_to_float` | `column` | any | See "Conversion failure policy" below. |
| `convert_to_date` / `convert_to_datetime` | `column` | any | Reuses Phase 3's exact conservative regex patterns (`app.profiling.type_detection`) — same explicit rules, not a separate guess. Output is always normalized to `YYYY-MM-DD` / `YYYY-MM-DDTHH:MM:SS`. |
| `drop_column` | `column` | any | Only ever applied when explicitly selected — never inferred automatically, even for an obviously-empty/constant column. |
| `remove_iqr_outliers` | `column` | numeric only | Recomputed live on the pipeline's *current* state at that step (not the stale Phase 3 numbers) — see "Operation order" below. No-op (not an error) if there are fewer than 4 non-null values or the IQR is zero. |

A fill operation on a column with zero non-null values (or zero rows at all) is a **safe no-op** (`affectedRows: 0`), never an error — this is what makes an entirely-empty dataset or an all-null column something the pipeline can pass over instead of crashing on.

### Conversion failure policy

For `convert_to_integer`, `convert_to_float`, `convert_to_date`, `convert_to_datetime`: if **any** non-null value in the column can't be safely converted, the **entire operation fails** (422) with a message naming how many values failed and a small sample — nothing is partially converted, nothing is silently turned into `null`.

This was a deliberate choice between two options the spec called out explicitly — fail the operation, or keep the original value and flag it. Keeping-and-flagging was rejected because a column can't simultaneously "be integers" and "still hold some original strings" — the resulting column would either have mixed types (defeating the point of a type conversion) or silently drop the unconvertible values as `null` (silent data loss). Failing outright, with a precise and actionable error, keeps every completed conversion **honest**: if a column shows as converted, every non-null value in it really was converted, no exceptions.

### Operation order matters

Operations apply strictly in the order given, each on the output of the one before it. The canonical example:

```json
{"operations": [
  {"type": "trim_whitespace", "column": "city"},
  {"type": "remove_duplicate_rows"}
]}
```

vs. the same two operations reversed. `"Paris"` and `"Paris "` are **not** duplicates until whitespace is trimmed — trim-then-dedupe can merge rows that dedupe-then-trim would leave as two separate rows. There is no "smart" reordering; the pipeline runs exactly the sequence it's given. `tests/test_cleaning.py::test_trim_before_duplicates_can_merge_rows_that_would_otherwise_differ` demonstrates this concretely.

## Pipeline validation

**Symfony** (`App\Cleaning\PipelineParser`) checks structure before anything is sent anywhere — 400 for: not a list, empty, more than 50 operations, an operation that isn't an object, an unrecognized `type`, a missing `column` where one is required, a missing `value` for `fill_constant`.

**The data engine** checks everything Symfony can't know — 422 for: a `column` that doesn't exist in the actual data, a type-incompatible operation (e.g. `fill_mean` on a string column), a value that can't be safely converted. It re-validates required parameters too, defensively, even though Symfony already checked — never trusting a caller blindly, even an internal one.

## Idempotency

Keyed on `sha256(sourceVersion.checksum + canonical JSON of the operations list)`, stored on `Analysis.pipelineFingerprint`. Requesting the exact same pipeline against the exact same source version reuses the existing **completed** `Analysis` — the data engine isn't called again. A prior `pending`/`running`/`failed` run for that fingerprint is never reused; the request always retries in that case. `?force=true` always starts a fresh run regardless of any existing result.

No cross-request locking (no job queue in this phase) — two concurrent first-time requests for the same never-yet-cleaned pipeline can both start a run. Same documented limitation as profiling (Phase 3).

## Before / after

Every completed cleaning run recomputes a full Phase 3 profile of the result (`report.after`) alongside the source's own profile (`report.before`) — both are genuine `ProfileResult`s, not summaries invented for display. This is what lets a later consumer (the eventual AI layer) state something like *"missing values dropped from 4.2% to 0.8%"* from real, freshly-computed numbers on both sides — never a guess, and never computed from stale Phase 3 data once the pipeline has actually changed things.

When nothing changed, `report.after` is identical to `report.before` (both point at the same computed profile) — the comparison is still valid, it just shows no movement.

## Checksum & no-op detection

"Changed" is decided by comparing the **parsed DataFrames** (`source.equals(cleaned)`), never by comparing raw file bytes. A re-serialized CSV can legitimately differ byte-for-byte from its source (different delimiter, quoting, line endings) even when the data itself is identical — a byte comparison would report `changed: true` on almost every run. Comparing the parsed content is the correct notion of "did the data change".

If nothing changed: no new file is written, no new `DatasetVersion` is created, and the response says so explicitly (`changed: false`) along with why (`report.before == report.after`).

If something changed: the backend recomputes the new file's SHA-256 and size **itself**, from the committed file — the data engine's own numbers are not blindly trusted, same authority model as Phase 2 ingestion.

## Transactions & failure handling

- **Nothing is committed until everything succeeds.** The data engine writes to `storage/tmp/`, never `storage/datasets/`; only a fully successful pipeline run reaches the point where Symfony moves that file into permanent storage.
- **No orphaned files.** If persisting the new `DatasetVersion`/`Analysis` fails *after* the file was already moved into permanent storage, the just-stored file is deleted as a compensating action (`CleaningService::commitCleanedFile()`).
- **A failed cleaning run creates no `DatasetVersion`.** The triggering HTTP request still succeeds (`201`) — the *request* to run cleaning succeeded even though the *cleaning* itself didn't — but `Analysis.status` is `failed`, `Analysis.resultVersion` stays `null`, and `Analysis.errorMessage` holds a sanitized, already-safe-to-display reason. No stack trace, path, or internal detail ever reaches the client (verified: `tests/test_clean_endpoint.py::test_clean_nonexistent_column_returns_422_without_leaking_paths`).
- **The requested pipeline is recorded before execution** (`Analysis.operations`), so even a failed run keeps an exact, inspectable record of what was attempted.

## Preview

`POST .../clean/preview` runs the identical pipeline (same validation, same size limits) but never writes anything and never creates an `Analysis` or `DatasetVersion` — a pure "what would happen" read. It was reliably implementable without extra complexity because the pipeline function already separates "compute the result" from "persist the result"; preview just stops before the second half.

`estimatedAffectedRows` is the **sum** of each operation's own `affectedRows` — which can double-count a row touched by more than one operation in the pipeline (e.g. a row that both gets trimmed *and* is part of a removed duplicate pair). A precise unique-row count across heterogeneous row-adding/-removing/-transforming operations would need meaningfully more machinery for a number that's explicitly only an estimate; documented here rather than silently over-engineered.

## Limits

Reuses Phase 3's exact size/row/column limits (`DATA_ENGINE_MAX_PROFILABLE_BYTES`, `DATA_ENGINE_MAX_ROWS`, `DATA_ENGINE_MAX_COLUMNS`) for reading the *source* file — the same "reject outright rather than silently truncate" policy applies (`docs/data-profiling.md`). A cleaning pipeline can only ever reduce or hold steady the row/column count (no operation adds rows or columns), so no separate output-side limit is needed.

## Security

Every Phase 1–3 guarantee still holds: JWT authentication required on every cleaning endpoint, dataset **and** version ownership checked before any work starts (`404` if the version doesn't belong to the given dataset, even if the version id is real), storage identifiers re-validated for path traversal/symlinks independently on both sides, size limits enforced, no secrets or dataset content in logs.

**The client only ever sends a `datasetVersionId` (implicitly, via the URL) and a pipeline of operations — never a file path.** The server alone decides which physical file gets read, from the `storagePath` already on record for that version; there is no field anywhere in the cleaning request a client could use to point at an arbitrary file.

## API

```
GET  /api/datasets/{datasetId}/versions/{versionId}/cleaning-recommendations
POST /api/datasets/{datasetId}/versions/{versionId}/clean/preview
POST /api/datasets/{datasetId}/versions/{versionId}/clean          [?force=true]
GET  /api/datasets/{datasetId}/versions/{versionId}/clean            (latest cleaning Analysis, without triggering)
```

**Recommendations** require an existing **completed** profiling `Analysis` for that version (`404`, "profile it first", if there isn't one) — recommendations are only ever derived from real computed data, so there must be real computed data to derive them from.

**Clean/preview request:**
```json
{
  "operations": [
    { "type": "trim_whitespace", "column": "city" },
    { "type": "fill_median", "column": "age" },
    { "type": "remove_duplicate_rows" }
  ]
}
```

**Clean response:**
```json
{
  "analysisId": "...",
  "status": "completed",
  "sourceVersionId": "...",
  "resultVersionId": "...",
  "changed": true,
  "report": {
    "operations": [
      { "operation": "trim_whitespace", "column": "city", "affectedRows": 342 },
      { "operation": "fill_median", "column": "age", "affectedRows": 43, "before": {"nullCount": 43}, "after": {"nullCount": 0} },
      { "operation": "remove_duplicate_rows", "affectedRows": 127, "beforeRows": 10000, "afterRows": 9873 }
    ],
    "before": { "...": "full ProfileResult" },
    "after": { "...": "full ProfileResult" }
  },
  "errorMessage": null,
  "operations": [ "...the requested pipeline, as submitted..." ],
  "createdAt": "...", "startedAt": "...", "completedAt": "..."
}
```

**Preview response:**
```json
{
  "estimatedAffectedRows": 127,
  "operations": [ "...same per-operation report shape as above..." ],
  "before": { "...": "full ProfileResult" },
  "after": { "...": "full ProfileResult" }
}
```

**Errors** always follow the project-wide `{"status": "error", "message": "..."}` shape:

| Status | Meaning |
|---|---|
| 400 | Malformed pipeline (unknown type, missing required param, empty pipeline) |
| 401 | Unauthenticated |
| 403 | Dataset exists but isn't yours |
| 404 | Dataset/version doesn't exist, version doesn't belong to that dataset, or (recommendations) no profile yet |
| 413 | Source file too large / too many rows / too many columns to load |
| 422 | Well-formed pipeline, but the data engine can't apply it (bad column, wrong type, unconvertible values) |
| 500 | Unexpected internal error |
