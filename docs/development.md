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

No credential has a hardcoded fallback baked into source that isn't also overridable here — `docker-compose.yml` reads every credential through `${VAR:-default}`.

Ports were chosen to avoid clashing with other projects that may already be running on a dev machine (standard Symfony/Angular defaults like 8000/4200 are deliberately not used at the compose level).

## Running each service natively (without Docker)

**Backend:**
```bash
cd backend
composer install
# create .env.local with a real DATABASE_URL pointing at a local Postgres
php bin/console doctrine:migrations:migrate
php -S localhost:8000 -t public
php bin/phpunit
```

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
├── docs/             Architecture and development docs (this file)
├── .github/          CI workflows
├── docker-compose.yml
└── README.md
```

## Remaining issues / deliberate deferrals

- **Migrations are not auto-run on container start.** Running migrations automatically on boot is a production-deployment concern (rollback strategy, zero-downtime considerations) that belongs to the "Production readiness" phase, not Foundation. For now, run them manually (see above).
- **`doctrine/orm` is pinned to `^3.6,!=3.6.8`.** Version `3.6.8` (current at the time of writing) ships a `GenerateSchemaEventArgs::setSchema()` that unconditionally requires DBAL's not-yet-stable `Schema::edit()` API (DBAL `^4.5`, which only has a `dev` release), which breaks every schema-generating command (`doctrine:migrations:diff`, `doctrine:schema:update`) even though it's guarded with `method_exists()`. This is an upstream version-skew bug, not a project defect. Revisit this pin once a fixed `doctrine/orm` patch or a stable DBAL `4.5` ships.
- **No PHPStan/ESLint yet.** There's no business logic to statically analyze yet beyond the entities/health endpoint; static analysis tooling will be added once real logic lands in the ingestion phase, to avoid a lint config with nothing meaningful to check.
- **JWT auth is installed but not wired to any endpoint.** `lexik/jwt-authentication-bundle` and `symfony/security-bundle` are configured; register/login endpoints and route protection will be added once there's something worth protecting (dataset ownership, in the ingestion phase).
