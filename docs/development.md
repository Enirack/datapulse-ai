# Development guide

## Prerequisites

- Docker + Docker Compose (recommended path — no local PHP/Python/Node setup needed)
- Or, to run services natively: PHP 8.4+ with Composer, Python 3.13+, Node 22+

## Quick start (Docker)

```bash
cp .env.example .env
docker compose up --build
```

| Service     | URL                                | Container health check |
|-------------|--------------------------------------|-------------------------|
| Frontend    | http://localhost:4210                | `wget` against `/` |
| Backend API | http://localhost:8020/api/health     | `wget` against `/api/health` |
| Data engine | http://localhost:8021/health         | `python -c urllib.request` against `/health` |
| PostgreSQL  | localhost:5434                       | `pg_isready` |

All four containers report `healthy` in `docker compose ps` once ready. `depends_on: condition: service_healthy` means `backend` won't start until `postgres` and `data-engine` are healthy, and `frontend` won't start until `backend` is healthy.

> Container healthchecks call `127.0.0.1`, not `localhost` — Alpine's `wget` and some runtimes resolve `localhost` to `::1` first, and neither the PHP dev server nor the app binds IPv6, so `localhost` would report unhealthy even though the service is up.

### Run database migrations

The application containers do **not** auto-run migrations on start (that's a deliberate choice — see "Remaining issues" below). After the stack is up:

```bash
docker compose exec backend php bin/console doctrine:migrations:migrate --no-interaction
```

### JWT keys and uploaded files persist across restarts

`docker/docker-entrypoint.sh` generates the JWT signing keypair on first container start if `config/jwt/{private,public}.pem` are missing, and creates `var/storage/datasets`. Both are backed by named volumes (`backend_jwt`, `backend_storage`) so tokens stay valid and uploaded files survive a `docker compose restart` — only `docker compose down -v` wipes them. Since phase 4, `backend_storage` is also mounted read-write into the data engine (previously read-only) so a cleaning result can be handed off via `storage/tmp/` without the file crossing the wire as an HTTP payload — see `docs/data-cleaning.md` "Storage sharing".

### Try the API

```bash
# Register + log in
curl -X POST http://localhost:8020/api/auth/register -H "Content-Type: application/json" \
  -d '{"email":"you@example.com","password":"a-strong-password"}'
TOKEN=$(curl -s -X POST http://localhost:8020/api/auth/login -H "Content-Type: application/json" \
  -d '{"email":"you@example.com","password":"a-strong-password"}' | grep -o '"token":"[^"]*"' | cut -d'"' -f4)

# Upload a dataset
curl -X POST http://localhost:8020/api/datasets -H "Authorization: Bearer $TOKEN" \
  -F "file=@./my-data.csv" -F "name=My dataset"

# List / fetch / delete
curl http://localhost:8020/api/datasets -H "Authorization: Bearer $TOKEN"
curl http://localhost:8020/api/datasets/<id> -H "Authorization: Bearer $TOKEN"
curl -X DELETE http://localhost:8020/api/datasets/<id> -H "Authorization: Bearer $TOKEN"

# Profile a dataset version (id from the upload response's versions[0].id)
curl -X POST "http://localhost:8020/api/datasets/<datasetId>/versions/<versionId>/profile" -H "Authorization: Bearer $TOKEN"
curl "http://localhost:8020/api/datasets/<datasetId>/versions/<versionId>/profile" -H "Authorization: Bearer $TOKEN"

# Get cleaning recommendations (requires the profile call above to have completed first)
curl "http://localhost:8020/api/datasets/<datasetId>/versions/<versionId>/cleaning-recommendations" -H "Authorization: Bearer $TOKEN"

# Preview, then apply, a cleaning pipeline
curl -X POST "http://localhost:8020/api/datasets/<datasetId>/versions/<versionId>/clean/preview" -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" -d '{"operations":[{"type":"remove_duplicate_rows"}]}'
curl -X POST "http://localhost:8020/api/datasets/<datasetId>/versions/<versionId>/clean" -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" -d '{"operations":[{"type":"remove_duplicate_rows"}]}'
```

Full profiling contract, security model, and limits: [`data-profiling.md`](./data-profiling.md). Full cleaning contract, transformation catalog, and idempotency strategy: [`data-cleaning.md`](./data-cleaning.md).

## Environment variables

Defined in `.env.example` at the repo root (copy to `.env`, which is git-ignored):

| Variable | Purpose | Default |
|---|---|---|
| `POSTGRES_DB` / `POSTGRES_USER` / `POSTGRES_PASSWORD` | PostgreSQL credentials | `datapulse` / `datapulse` / `changeme` |
| `POSTGRES_PORT` | Host port for Postgres | `5434` |
| `BACKEND_PORT` | Host port for the Symfony API | `8020` |
| `DATABASE_URL` | Doctrine DBAL connection string | derived from the Postgres vars above |
| `CORS_ALLOW_ORIGIN` | Regex of allowed browser origins | `localhost`/`127.0.0.1`, any port |
| `DATA_ENGINE_PORT` | Host port for FastAPI | `8021` |
| `DATA_ENGINE_URL` | Internal URL the backend will use to call the data engine | `http://data-engine:8001` |
| `FRONTEND_PORT` | Host port for the Angular app (served via nginx) | `4210` |
| `MAX_UPLOAD_SIZE` | Max accepted dataset upload size, in bytes | `52428800` (50 MiB) |
| `MAX_PROFILABLE_BYTES` | Max file size the data engine will load into memory to profile | `26214400` (25 MiB) |

`DATASET_STORAGE_DIR`/`DATASET_TMP_DIR` (Symfony) and the data engine's own `DATA_ENGINE_STORAGE_DIR`/`DATA_ENGINE_TMP_DIR`/`DATA_ENGINE_MAX_ROWS`/`DATA_ENGINE_MAX_COLUMNS` are not exposed as root-level/compose variables on purpose — they're internal container paths and tuning knobs (see `docker-compose.yml`, `docs/data-profiling.md`, `docs/data-cleaning.md`), never something a host-level `.env` should redirect.

No credential has a hardcoded fallback baked into source that isn't also overridable here — `docker-compose.yml` reads every credential through `${VAR:-default}`.

Ports were chosen to avoid clashing with other projects that may already be running on a dev machine (standard Symfony/Angular defaults like 8000/4200 are deliberately not used at the compose level).

## Running each service natively (without Docker)

**Backend:**
```bash
cd backend
composer install
# create .env.local with a real DATABASE_URL pointing at a local Postgres
php bin/console lexik:jwt:generate-keypair --skip-if-exists
php bin/console doctrine:migrations:migrate
php -S localhost:8000 -t public
```

To run the test suite locally, create `.env.test.local` pointing `DATABASE_URL` at a real Postgres (tests need a live database — auth and dataset tests are functional, not mocked), then create + migrate the `_test` database once:
```bash
php bin/console doctrine:database:create --env=test --if-not-exists
php bin/console doctrine:migrations:migrate --env=test --no-interaction
php bin/phpunit
```

Profiling and cleaning tests are the one exception to "hit the real thing": `App\Profiling\DataEngineClientInterface` (which now covers both) is bound to `App\Tests\Support\FakeDataEngineClient` `when@test` (see `config/services.yaml`), because a natively-run PHPUnit process has no access to the Docker-shared storage volume a real data-engine call would need to read from. The fake still writes a real file under the tmp dir when simulating a data-changing clean, so `CleaningService`'s real commit-to-permanent-storage path is genuinely exercised — only the network/Python boundary is faked. Profiling/cleaning *correctness* is covered by the data engine's own test suite and by manual Docker verification, not by backend unit tests.

**Data engine:**
```bash
cd data-engine
python -m venv .venv
.venv/Scripts/activate   # source .venv/bin/activate on Linux/macOS
pip install -r requirements-dev.txt
uvicorn app.main:app --reload --port 8001
pytest
```

**Frontend:**
```bash
cd frontend
npm install
npm start          # dev server on :4200
npx ng test --watch=false
npx ng build
```

## Docker commands reference

```bash
docker compose up --build       # build + start everything
docker compose up -d <service>  # start/restart one service
docker compose build <service>  # rebuild one image after dependency changes
docker compose logs -f <service>
docker compose ps
docker compose exec backend sh
docker compose down              # stop and remove containers (keeps the postgres volume)
docker compose down -v           # also wipe the postgres volume (destroys local data)
```

## Repository layout

```
datapulse-ai/
├── frontend/         Angular 22 app
├── backend/          Symfony 8 API
├── data-engine/      FastAPI data processing service
├── infrastructure/   Cross-cutting deployment config (nginx, etc.)
├── docs/             Architecture, development, and data-profiling docs
├── .github/          CI workflows
├── docker-compose.yml
└── README.md
```

## Remaining issues / deliberate deferrals

- **Migrations are not auto-run on container start.** Running migrations automatically on boot is a production-deployment concern (rollback strategy, zero-downtime considerations) that belongs to the "Production readiness" phase, not Foundation. For now, run them manually (see above).
- **`doctrine/orm` is pinned to `^3.6,!=3.6.8`.** Version `3.6.8` (current at the time of writing) ships a `GenerateSchemaEventArgs::setSchema()` that unconditionally requires DBAL's not-yet-stable `Schema::edit()` API (DBAL `^4.5`, which only has a `dev` release), which breaks every schema-generating command (`doctrine:migrations:diff`, `doctrine:schema:update`) even though it's guarded with `method_exists()`. This is an upstream version-skew bug, not a project defect. Revisit this pin once a fixed `doctrine/orm` patch or a stable DBAL `4.5` ships.
- **No PHPStan/ESLint/mypy yet.** Deferred again this phase — four phases of real business logic now exist without it; worth reconsidering before phase 5 adds more.
- **A `Dataset` can only get a new `DatasetVersion` via upload or cleaning.** There's still no "upload a replacement file for an existing dataset" endpoint — only cleaning produces a version 2+, and only ever as `kind: cleaned`.
- **Cleaning has no cross-request locking**, same documented limitation as profiling — see `docs/data-cleaning.md#idempotency`.
- **Cleaning's `estimatedAffectedRows` preview number can double-count** a row touched by more than one operation in the pipeline — documented in `docs/data-cleaning.md#preview` as a deliberate simplicity/precision tradeoff for a number that's explicitly only an estimate.
- **Whitespace recommendations only fire on whitespace-*only* cells**, not general leading/trailing whitespace on otherwise-normal values — a real gap in Phase 3's profiling contract (no such stat exists there), documented rather than worked around by re-reading the file at recommendation time. See `docs/data-cleaning.md`.
- **Duplicate-checksum detection is informational only.** A re-upload of identical content creates a brand new `Dataset` + `DatasetVersion` and merely reports `possibleDuplicateOfDatasetId` in the response — it does not block or dedupe storage. Deliberate: rejecting a legitimate re-upload would be more surprising than helpful at this phase.
- **No per-route rate limiting on `/api/auth/*`.** Brute-force/credential-stuffing protection is out of scope until there's a reason to harden further; noted here rather than silently skipped.
- **Dataset listing does one query per item for its versions** (`DatasetController::list()`). Fine at the volumes this phase deals with; would want a join or a batched fetch before that stops being true.
- **Profiling has no cross-request locking.** Two concurrent first-time profile requests for the same version can both start a run (no job queue in this phase) — see `docs/data-profiling.md#idempotency`.
- **Native (non-Docker) backend dev can't actually run profiling end-to-end.** `DATA_ENGINE_URL` defaults to the Docker-exposed port for local dev, but the data engine's file access depends on the shared `backend_storage` Docker volume — a natively-run backend writes dataset files to the host filesystem instead, which the containerized data engine can't see. Full Docker Compose is the only path that supports profiling end-to-end; documented, not solved, since mixing native-backend-with-dockerized-data-engine isn't a setup worth specifically supporting.
- **CSV/XLSX/JSON profiling assumes a header row and profiles only the first XLSX sheet.** Both are explicit, documented simplifications (`docs/data-profiling.md`) rather than attempts at full generality.
