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
  -> anomaly detection
  -> visualization data
  -> structured insights
  -> AI interpretation
  -> natural-language questions
  -> report
```

## Services

| Service       | Stack                                          | Responsibility |
|---------------|-------------------------------------------------|----------------|
| `frontend`    | Angular 22, TypeScript                          | User interface: dashboard, dataset upload, visualizations, chat with the AI analyst. Talks only to `backend`. |
| `backend`     | Symfony 8, PHP 8.4, Doctrine ORM, PostgreSQL     | REST API, authentication (JWT), persistence, request validation, orchestration of the data engine, and (later) the AI layer. |
| `data-engine` | Python, FastAPI, Pandas, NumPy, SciPy            | The deterministic analytical core: parsing, profiling, cleaning, statistics, anomaly detection. Produces structured, computed results — never prose. |

The frontend never talks to the data engine directly, and the data engine never talks to the frontend. The backend is the single entry point and the only service with a database connection; it is also the only service that will hold API credentials for the AI provider once the AI layer is built, so those never reach the browser or the data engine.

## Communication between services

- **frontend → backend**: HTTPS/JSON over `/api/*`. In production this is proxied by nginx (see `infrastructure/nginx/frontend.conf`); in development it's called directly at `http://localhost:8020`.
- **backend → data-engine**: HTTP/JSON over the internal Docker network (`http://data-engine:8001`), not exposed to the browser. Not yet implemented — will be introduced in the Data ingestion / profiling phases, since there is no processing to delegate yet.
- **backend → PostgreSQL**: Doctrine DBAL over the internal network.

## Domain model (schema, not yet wired to endpoints)

Six entities exist as Doctrine mappings and a migration, establishing the shape of the domain before ingestion is built:

- **User** — account, authenticates via JWT (bundle installed, not yet wired to endpoints).
- **Dataset** — an uploaded dataset owned by a `User`. Tracks status (`uploaded` → `processing` → `ready` / `failed`).
- **DatasetVersion** — an immutable snapshot of a dataset's data, either `raw` (exactly as uploaded, never modified) or `cleaned` (a derived, traceable transformation). This is what makes "never silently modify user data" enforceable at the schema level: cleaning always produces a new version, never an in-place edit.
- **Analysis** — one run of a specific analysis type (profiling, statistics, anomaly detection, …) against a `DatasetVersion`.
- **Insight** — a single finding from an `Analysis`, tagged with a `kind`: `computed_fact`, `statistical_interpretation`, or `recommendation`. This distinction exists so the AI layer (and the UI) can never blur "the mean is 42" with "this looks anomalous" with "consider investigating X" — they are different kinds of claim with different evidentiary weight.
- **Report** — a generated, shareable output built from a dataset's insights.

No controllers or business logic use these entities yet; ingestion (phase 2) is what will start populating them.

## Error handling

All exceptions raised under `/api/*` are caught by `App\EventListener\ApiExceptionListener` and converted to a consistent JSON shape:

```json
{ "status": "error", "message": "..." }
```

Server errors (5xx) never leak their real message or stack trace to the client — only a generic "Internal server error" — while the full exception is logged server-side via Monolog. Client errors (4xx, e.g. 404) return their actual message, since it's safe and useful (e.g. "No route found").

## Logging

Monolog is configured per environment (`config/packages/monolog.yaml`):
- `dev`: verbose, written to `var/log/dev.log` and the console.
- `prod`: buffered, `error`-level-triggered, written as JSON to `stderr` (container-log friendly).

## Security posture at this stage

- JWT bundle and Symfony Security are installed and configured but not yet wired to any endpoint — there is nothing to protect yet.
- CORS is restricted to `localhost`/`127.0.0.1` origins by default (`CORS_ALLOW_ORIGIN`).
- No secrets are committed. `.env` in `backend/` ships with the Symfony skeleton's public dev defaults (not secrets); real values are meant to go in the git-ignored `.env.local` or in Docker Compose environment variables.
- Untrusted-input protections (path traversal, decompression bombs, oversized files, CSV injection, malicious spreadsheet content) are **not implemented yet** — they belong to the Data ingestion phase, where file upload actually exists.

## Local development

See [`development.md`](./development.md).
