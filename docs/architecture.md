# Architecture

## Overview

DataPulse AI turns uploaded tabular datasets into deterministic business intelligence, then uses an AI layer to interpret and explain the *results* of that deterministic processing — never to perform the analysis itself.

```
Dataset
  -> ingestion
  -> validation
  -> profiling
  -> cleaning
  -> statistical analysis
  -> visualization data      <- Phase 5 (current)
  -> structured insights
  -> AI interpretation
  -> natural-language questions
  -> report
```

## Services

| Service       | Stack                                          | Responsibility |
|---------------|-------------------------------------------------|----------------|
| `frontend`    | Angular 22, TypeScript, Chart.js                | User interface: dashboard, dataset upload, cleaning, analytics/visualizations (phase 5), and (later) chat with the AI analyst. Talks only to `backend`. |
| `backend`     | Symfony 8, PHP 8.4, Doctrine ORM, PostgreSQL     | REST API, authentication (JWT), persistence, request validation, file ingestion/storage, orchestration of the data engine, and (later) the AI layer. |
| `data-engine` | Python, FastAPI, Pandas, NumPy                   | The deterministic analytical core: parsing, profiling, cleaning, analytics (descriptive statistics + chart-ready data). Produces structured, computed results — never prose. Called by the backend for profiling (phase 3), cleaning (phase 4), and analytics (phase 5); an AI interpretation layer is still a future phase. |

The frontend never talks to the data engine directly, and the data engine never talks to the frontend. The backend is the single entry point and the only service with a database connection and a storage volume; it is also the only service that will hold API credentials for the AI provider once the AI layer is built, so those never reach the browser or the data engine.

## Communication between services

- **frontend → backend**: HTTPS/JSON over `/api/*`. In production this is proxied by nginx (see `infrastructure/nginx/frontend.conf`); in development it's called directly at `http://localhost:8020`.
- **backend → data-engine**: HTTP/JSON over the internal Docker network (`http://data-engine:8001`), not exposed to the browser. Used for profiling (phase 3, [`data-profiling.md`](./data-profiling.md)), cleaning (phase 4, [`data-cleaning.md`](./data-cleaning.md)), and analytics (phase 5, [`data-analytics.md`](./data-analytics.md)) — all three through the single `App\Profiling\DataEngineClientInterface`. The two services share a storage volume (read-write for both since phase 4 — see "Data profiling" below) so dataset files and cleaning results never cross the wire as HTTP payloads; analytics only ever reads from it.
- **backend → PostgreSQL**: Doctrine DBAL over the internal network.

## Authentication (phase 2)

JWT, issued by `lexik/jwt-authentication-bundle`:

- `POST /api/auth/register` — public. Creates a `User` with an Argon2id-hashed password (`auto` hasher). Returns the user (never the hash).
- `POST /api/auth/login` — public. Intercepted directly by the `login` firewall's `json_login` authenticator (not a real controller action) and returns `{"token": "..."}`.
- `GET /api/me` — requires a valid JWT. Returns the current user.

All other `/api/*` routes require `IS_AUTHENTICATED_FULLY` (`access_control` in `config/packages/security.yaml`), enforced by a stateless `api` firewall reading `Authorization: Bearer <jwt>`. Both the entry point (no/invalid token → 401) and the login failure handler (bad credentials → 401) are overridden with small classes in `App\Security` so every error on the API uses the same `{"status": "error", "message": "..."}` shape as everything else, instead of Lexik's default `{"code": ..., "message": ...}`.

JWT signing keys live at `backend/config/jwt/{private,public}.pem`, generated on first container start by `docker/docker-entrypoint.sh` if missing (dev-only convenience — they are not committed) and persisted in the `backend_jwt` named volume so tokens survive a container restart.

## Dataset ingestion (phase 2)

```
POST   /api/datasets        multipart/form-data: file (required), name, description (optional)
GET    /api/datasets        paginated, current user's datasets only
GET    /api/datasets/{id}   404 if it doesn't exist, 403 if it exists but isn't yours
DELETE /api/datasets/{id}   same ownership rule; also deletes the stored file(s)
```

All four require authentication. Ownership is checked explicitly in `DatasetController::findOwnedDataset()` (not a Symfony Voter) so the 404-vs-403 distinction — real 404 for a genuinely unknown id, 403 for "exists but not yours" — is exactly what the controller decides, not whatever a generic access-denied listener happens to produce.

### Domain model

- **User** — account. Password is Argon2id-hashed (`auto`), never serialized in any API response.
- **Dataset** — the logical dataset: `id`, `owner`, `name`, `description`, `status` (`uploaded → processing → ready / failed`; this phase only ever produces `ready` on success, since the whole pipeline runs synchronously in the request — nothing is left in an ambiguous `processing` state), `createdAt`, `updatedAt`.
- **DatasetVersion** — one uploaded or derived file: `id`, `dataset`, `versionNumber`, `kind` (`raw` for an upload, `cleaned` for a Phase 4 cleaning result), `originalFilename` (sanitized, display-only), `storagePath` (opaque server-generated storage identifier — **never returned by the API**), `mimeType` (detected, not trusted from the client), `fileSize`, `checksum` (SHA-256 hex), `hasFormulaInjectionRisk` (bool), `createdAt`, `sourceVersion` (nullable self-reference — null for an upload, set to the version a cleaning run started from otherwise; see [`data-cleaning.md`](./data-cleaning.md)).

A `Dataset` currently gets exactly one `DatasetVersion` (created together, atomically, by `POST /api/datasets`). Multiple versions per dataset (e.g. "add a new version of this dataset") is a natural fit for `DatasetVersion.versionNumber` but isn't exposed by any endpoint yet — out of scope for this phase.

### Storage

`App\Storage\DatasetStorageInterface` (`store() / retrieve() / delete() / exists()`), implemented for V1 by `LocalFilesystemDatasetStorage`. Swapping in an S3-compatible backend later means writing one new class against the same interface — nothing above the storage layer knows or cares where bytes actually live.

Every stored file's path is generated entirely by the storage class itself: `{2-char shard}/{uuid}.{extension}`, under `DATASET_STORAGE_DIR` (`var/storage/datasets`, outside `public/`, so the PHP dev server's docroot restriction alone makes it unreachable over HTTP — verified in `docs/development.md`). The user-supplied filename never touches a filesystem path at any point; it only ever becomes `DatasetVersion.originalFilename`, a plain display string. `retrieve()`/`delete()`/`exists()` re-resolve the identifier and verify with `realpath()` that it still lands inside the storage root before touching anything — defense in depth even though a well-formed identifier can only ever point inside the root by construction. Symlinked storage directories or symlinked stored files are treated as invalid/absent rather than followed.

### Upload validation pipeline

`App\Service\DatasetIngestionService::ingest()` runs in this order — nothing is persisted or written to permanent storage unless every step passes:

1. **Size** — checked against `MAX_UPLOAD_SIZE` (configurable, one place: `services.yaml` binds it from the env var; not hardcoded elsewhere) → `413` if exceeded. PHP's own `upload_max_filesize`/`post_max_size` (`docker/uploads.ini`) are set generously above this so the app-level check is always the one that actually fires.
2. **Filename** — `App\Upload\FilenameSanitizer` rejects null bytes, `/`, `\`, and `..` outright (`400`); otherwise strips control characters and caps length. In practice, for real HTTP multipart uploads, Symfony's `UploadedFile` already reduces the client-supplied name to a bare filename (`File::getName()` strips everything up to the last `/`) before this code ever sees it — so this is verified defense in depth (`tests/Upload/FilenameSanitizerTest.php` calls it directly with raw traversal strings), not the only thing standing between a malicious filename and the filesystem.
3. **Extension** — checked against a fixed whitelist (`csv`, `xlsx`, `json`) → `415` otherwise. `.xlsm` (macro-enabled) is rejected here simply by not being in the whitelist.
4. **MIME plausibility** — `App\Upload\FileTypeGuard` detects the real content type via `finfo` and cross-checks it against the extension → `415` on mismatch (e.g. a PNG renamed to `.csv`). The extension is never trusted alone.
5. **Structural validation** — one of `CsvValidator` / `XlsxValidator` / `JsonValidator` actually attempts a bounded, safe parse:
   - **CSV**: streamed via `fgetcsv` (O(1) memory per row, capped at 50,000 scanned rows), delimiter auto-detected from `,;\t|` by sampling the first lines, rejected (`422`) if zero rows parse. Every cell is scanned for spreadsheet formula injection (see below).
   - **JSON**: `json_decode` with a depth cap (512); malformed JSON → `422`. The file is not converted to tabular form yet — that's phase 3+; a top-level array of flat objects is the shape that conversion will expect.
   - **XLSX**: confirmed to be a real ZIP (magic bytes), opened with `ZipArchive`, checked for the OOXML parts a genuine workbook must have (`[Content_Types].xml`, `xl/workbook.xml`) → `422` if missing/corrupt. Entry count and total uncompressed size are capped, and any single entry with a >200:1 compression ratio is rejected — decompression-bomb protection without ever inflating the archive. Sheet *data* is never read (that's phase 3+ profiling); no macro can execute because nothing in this pipeline ever executes anything from the file.
6. **Checksum** — SHA-256 over the file, via `hash_file()` (streamed, not loaded into memory).
7. **Duplicate detection** — same checksum already uploaded by this user? Reported as `possibleDuplicateOfDatasetId` in the response. Informational only — never blocks the upload, since a user re-uploading the same file is not an error.
8. **Store**, then persist `Dataset` + `DatasetVersion` in one flush.

### CSV formula injection ("CSV injection")

Per OWASP guidance, a cell whose value starts with `=`, `+`, `-`, or `@` can be interpreted as a formula by spreadsheet software when the CSV is later opened in Excel/Sheets/etc. `CsvValidator` scans every cell (bounded, see above); if any cell starts with one of those characters **and isn't simply a plain signed number** (`-5`, `+3.2` are fine — real negative data is not a formula), `DatasetVersion.hasFormulaInjectionRisk` is set `true`.

**The original file is never modified because of this.** Detection is purely informational, recorded on the version, surfaced in the API response (`hasFormulaInjectionRisk`) — what the UI/consumer does with that signal (e.g. warn before anyone opens the file in Excel) is a later concern.

## Data profiling (phase 3)

```
POST /api/datasets/{datasetId}/versions/{versionId}/profile   trigger (idempotent by default; ?force=true bypasses reuse)
GET  /api/datasets/{datasetId}/versions/{versionId}/profile   fetch the latest analysis without triggering one
```

Same ownership rule as dataset endpoints, plus a version-scoped check: `404` if the version id doesn't belong to the given dataset. Persisted on the `Analysis` entity (extended this phase with `result`, `resultSchemaVersion`, `errorMessage`, `startedAt`). Full contract, security model, size limits, and type-detection rules: [`data-profiling.md`](./data-profiling.md).

## Data cleaning (phase 4)

```
GET  /api/datasets/{datasetId}/versions/{versionId}/cleaning-recommendations   derived from the stored profile — no data-engine call
POST /api/datasets/{datasetId}/versions/{versionId}/clean/preview              dry run, writes nothing
POST /api/datasets/{datasetId}/versions/{versionId}/clean                      idempotent by default; ?force=true bypasses reuse
GET  /api/datasets/{datasetId}/versions/{versionId}/clean                      fetch the latest cleaning analysis without triggering one
```

Same ownership + version-scoping rules as profiling. A successful, data-changing clean creates a new `DatasetVersion` (`kind: cleaned`, `sourceVersion` pointing back at the version that was cleaned) — the version that was cleaned is never modified. `Analysis` gained `resultVersion` (the produced version, nullable), `operations` (the requested pipeline, JSON), and `pipelineFingerprint` (idempotency key) this phase. Full contract, transformation catalog, recommendation rules, conversion-failure policy, and idempotency strategy: [`data-cleaning.md`](./data-cleaning.md).

## Data analytics (phase 5)

```
POST /api/datasets/{datasetId}/versions/{versionId}/analytics   idempotent by default; ?force=true bypasses reuse
GET  /api/datasets/{datasetId}/versions/{versionId}/analytics   fetch the latest analytics analysis without triggering one
```

Same ownership + version-scoping rules as profiling and cleaning. Read-only — never writes a file, never creates a `DatasetVersion`. Reuses `Analysis` as-is (`type: analytics`; the `operations`/`pipelineFingerprint` fields introduced for cleaning are reused generically as "configuration/fingerprint", not redeclared). Column selection (numeric/categorical/temporal/unanalyzed), all statistics, histograms, box plots, a Pearson correlation matrix, and scatter plots are all deterministic — no AI/ML anywhere in this phase. Full contract, chart types, automatic-selection rules, limits, and chart library choice: [`data-analytics.md`](./data-analytics.md).

## Error handling

All exceptions raised under `/api/*` are caught by `App\EventListener\ApiExceptionListener` and converted to a consistent JSON shape:

```json
{ "status": "error", "message": "..." }
```

Server errors (5xx) never leak their real message or stack trace to the client — only a generic "Internal server error" — while the full exception is logged server-side via Monolog. Client errors (4xx) return their actual message.

Upload-specific rejections (`App\Upload\UploadRejectedException`) are caught directly in `DatasetController` rather than by the generic listener, since each one already carries its correct status code (`400`/`413`/`415`/`422`) from where it was raised.

| Status | Meaning | Example |
|---|---|---|
| 400 | Invalid request | Missing file, unsafe filename |
| 401 | Unauthenticated | No/invalid JWT |
| 403 | Unauthorized | Dataset exists but belongs to another user |
| 404 | Not found | Dataset id doesn't exist at all |
| 413 | File too large | Exceeds `MAX_UPLOAD_SIZE` |
| 415 | Unsupported file type | Bad extension, or content doesn't match the declared type |
| 422 | Invalid content | Malformed CSV/JSON/XLSX, duplicate registration email, weak password |

## Logging

Monolog is configured per environment (`config/packages/monolog.yaml`):
- `dev`: verbose, written to `var/log/dev.log` and the console.
- `prod`: buffered, `error`-level-triggered, written as JSON to `stderr` (container-log friendly).

## Security posture at this stage

- Authentication is real and enforced: every `/api/*` route except health/register/login requires a valid JWT.
- Passwords are hashed with Symfony's `auto` hasher (Argon2id where available); never returned by any endpoint.
- Dataset ownership is enforced on every read/write; cross-user access is a `403`, a nonexistent id is a `404`.
- Uploaded files are treated as fully untrusted: extension whitelist, MIME cross-check, structural validation, size limits, decompression-bomb protection for XLSX, CSV formula-injection detection, path-traversal-proof storage identifiers, symlink-attack-proof storage resolution. See "Upload validation pipeline" above for the full list mapped to spec requirements.
- Nothing from an uploaded file is ever executed — not as a shell command, not as a macro, not as a dependency install.
- The data engine never trusts a storage identifier blindly either, even though it only ever receives one from the trusted backend — same path-containment and symlink checks as the PHP storage class, independently implemented in Python. It has no database access and is never reachable from the browser.
- Cleaning requests never carry a file path — only a pipeline of operations. The server alone decides which physical file gets read, from the `storagePath` already on record for the version in the URL; there is no field anywhere in the request a client could use to point at an arbitrary file.
- Analytics requests never carry a file path either — only the version id in the URL. The analytics result itself never exposes the physical storage path or any filesystem detail.
- AI Analyst insights never inspect raw files or execute code — they strictly reason over deterministic structured metrics from Phase 3 (Profiling) and Phase 5 (Analytics), guaranteeing 100% grounded facts with zero data fabrication.

## AI Analyst (phase 6)

The AI Analyst layer synthesizes executive summaries, calculates dataset hygiene health scores, categorizes actionable findings, and answers analytical questions.

Key architectural guarantees:
- **Zero Fabrication**: The AI never calculates metrics or inspects raw data rows directly; it is strictly grounded in the verified profile and analytics results.
- **Explicit Insight Classification**: Every insight generated is typed as `computed_fact`, `statistical_interpretation`, or `recommendation` (`InsightKind`).
- **Grounded Q&A**: Answers to questions reference computed metrics (`referencedMetrics`) and declare their grounded status.
- **Provider Agnostic**: Operates with a self-contained, offline deterministic engine (`RuleBasedAiClient`) in test/CI/dev without requiring external API keys, while supporting external LLM integration via `AiClientInterface`.
Full contract and schema: [`docs/ai-analyst.md`](./ai-analyst.md).
- CORS is restricted to `localhost`/`127.0.0.1` origins by default (`CORS_ALLOW_ORIGIN`).
- No secrets are committed. `.env` in `backend/` ships with the Symfony skeleton's public dev defaults plus non-secret app defaults (`MAX_UPLOAD_SIZE`, `DATASET_STORAGE_DIR`); JWT keys and real per-environment secrets are git-ignored (`.env.local`, `config/jwt/*.pem`).
- Still deferred: rate limiting on auth endpoints, account lockout, email verification, refresh tokens — none of these are needed for a single-request ingestion flow and would be premature before there's a reason to harden auth further.

## Local development

See [`development.md`](./development.md).
