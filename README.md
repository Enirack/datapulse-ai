# DataPulse AI

An AI-powered data analytics platform. Users upload tabular datasets (CSV, XLSX, JSON) and the platform turns them into deterministic, structured business intelligence — profiling, cleaning, statistics, anomaly detection, and visualizations — which an AI layer then interprets and explains in natural language. The AI never performs the analysis itself; it only reasons over structured, computed results, and every number a user sees traces back to an actual computation.

This is not a chatbot wrapper. It's a real data-processing pipeline with an AI layer on top.

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

## Stack

| Service       | Stack                                          |
|---------------|--------------------------------------------------|
| `frontend`    | Angular 22, TypeScript                            |
| `backend`     | Symfony 8, PHP 8.4, Doctrine ORM, PostgreSQL, JWT  |
| `data-engine` | Python, FastAPI, Pandas, NumPy, SciPy              |

Full architecture, service responsibilities, and inter-service communication: [`docs/architecture.md`](docs/architecture.md).

## Repository layout

```
datapulse-ai/
├── frontend/         Angular 22 app
├── backend/          Symfony 8 API
├── data-engine/      FastAPI data processing service
├── infrastructure/   Cross-cutting deployment config (nginx, etc.)
├── docs/             Architecture and development docs
├── .github/          CI workflows
├── docker-compose.yml
└── README.md
```

## Quick start

```bash
cp .env.example .env
docker compose up --build
```

| Service     | URL                                |
|-------------|---------------------------------------|
| Frontend    | http://localhost:4210                 |
| Backend API | http://localhost:8020/api/health      |
| Data engine | http://localhost:8021/health          |
| PostgreSQL  | localhost:5434                        |

After the stack is up, apply database migrations once:

```bash
docker compose exec backend php bin/console doctrine:migrations:migrate --no-interaction
```

Full setup (including running each service natively without Docker), environment variables, and Docker command reference: [`docs/development.md`](docs/development.md).

## Development phases

This project is built incrementally, one phase at a time, never skipping ahead:

1. Foundation — monorepo scaffolding, health checks, Docker Compose, domain schema
2. Data ingestion — JWT auth, secure file upload, validation, untrusted-input protections
3. **Data profiling** — deterministic type detection, statistics, data quality, in the Python engine *(current)*
4. Data cleaning and analysis
5. Visualization and insights
6. AI analyst
7. Professional UI
8. Production readiness

## Data principles

- The original uploaded dataset is never modified. Cleaning produces a separate, traceable `DatasetVersion` — never an in-place edit.
- No analytical result is ever fabricated. Every number shown to a user originates from an actual computation over the dataset.
- If a dataset doesn't contain enough information to answer a question, the platform says so instead of guessing.
- Insights are explicitly tagged as a `computed_fact`, a `statistical_interpretation`, or a `recommendation` — never presented as if they were the same kind of claim.
- Uploaded files are treated as untrusted input. Path traversal, decompression bombs, oversized files, memory exhaustion, CSV injection, and malicious spreadsheet content are all actively guarded against — see [`docs/architecture.md`](docs/architecture.md#dataset-ingestion-phase-2) for the full pipeline.

## Tests

```bash
cd backend && php bin/phpunit
cd data-engine && .venv/Scripts/python.exe -m pytest   # or your venv's python
cd frontend && npx ng test --watch=false
```

All three run automatically in CI on every push/PR ([`.github/workflows/ci.yml`](.github/workflows/ci.yml)).

## Status

**Data profiling phase complete.** An uploaded dataset can be profiled on demand: the Symfony backend orchestrates, the Python data engine reads the file from a storage volume it shares with the backend (no file bytes over HTTP) and computes row/column counts, per-column business types (integer/float/boolean/string/date/datetime/categorical/unknown), null/unique/duplicate stats, numeric statistics, categorical top-values, and IQR outliers — all against a versioned JSON contract, never leaking NaN/Infinity, never exceeding documented size limits. Results persist on the `Analysis` entity; requests are idempotent by default. A minimal Angular UI (login, dataset list/upload, "Analyser" + results table) proves the frontend consumes it correctly. Full contract: [`docs/data-profiling.md`](docs/data-profiling.md). No cleaning, anomaly detection, visualization, or AI yet — that starts in Phase 4.
