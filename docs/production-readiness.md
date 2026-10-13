# DataPulse AI — Production Readiness & Operations Guide

This guide details the production architecture, security hardening, container deployment, monitoring, and backup procedures for **DataPulse AI**.

---

## 1. Production Architecture & Network Topology

In production, all services communicate over an isolated internal Docker bridge network (`datapulse`). **Only the Nginx Ingress Proxy exposes public ports (80/443)**; internal databases and engines are never accessible directly from the host or external internet.

```
                           Internet / User
                                 │
                                 ▼
                     ┌───────────────────────┐
                     │ Nginx Ingress Gateway │ (Port 80 / 443)
                     │   - TLS Termination   │
                     │   - Angular SPA       │
                     │   - Security Headers  │
                     │   - Gzip Compression  │
                     │   - 50MB Upload Limit │
                     └──────────┬────────────┘
                                │
               Internal Private Docker Network (datapulse)
                                │
          ┌─────────────────────┴─────────────────────┐
          │ (Reverse Proxy /api)                      │
          ▼                                           ▼
┌───────────────────┐                       ┌───────────────────┐
│  Symfony Backend  │                       │   FastAPI Engine  │
│  (Port 8000 int)  │◄──Shared Storage─────►│  (Port 8001 int)  │
│  - JWT Auth       │   (Datasets & Tmp)    │  - Profiling      │
│  - Auto-Migration │                       │  - Cleaning       │
│  - AI Grounding   │                       │  - Analytics      │
└─────────┬─────────┘                       └───────────────────┘
          │
          ▼
┌───────────────────┐
│ PostgreSQL 16 DB  │
│  (Port 5432 int)  │
│  - Persistent vol │
└───────────────────┘
```

---

## 2. Security Hardening Checklist

| Domain | Control | Implementation |
|---|---|---|
| **Network Exposure** | No internal services exposed | Only Nginx maps port 80/443. PostgreSQL and internal APIs are bound strictly inside the bridge network. |
| **HTTP Headers** | Prevent clickjacking, MIME sniffing, XSS | Nginx injects `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy`. |
| **Asset Compression & Caching** | High performance & DDoS mitigation | Gzip enabled for all text/code types. Static hashed JS/CSS assets cached with `Cache-Control: public, max-age=31536000, immutable`. |
| **File Ingestion Protection** | Protect against decompression bombs & formula injection | 50MB upload cap enforced at both Nginx (`client_max_body_size 50M`) and backend. Automated formula injection inspection flags spreadsheet commands (`=`, `+`, `-`, `@`, `\|`). |
| **Storage Scoping** | Read-only separation for untrusted operations | Data engine mounts backend storage with strict directory partitioning: reads only under `storage/datasets/` and writes only temporary files to `storage/tmp/`. |
| **Automated Migrations** | Deterministic container boot | Backend `docker-entrypoint.sh` automatically checks connectivity and executes `doctrine:migrations:migrate --no-interaction --allow-no-migration` on boot. |
| **JWT Keypair Management** | Non-ephemeral tokens | RSA keys generated on first boot and persisted across restarts in the `backend_jwt` Docker volume. |
| **Log Management** | Prevent disk exhaustion | Docker daemon logs capped via `json-file` driver (`max-size: 10m`, `max-file: 3`). |

---

## 3. Production Deployment Procedure

### Step 1: Prepare Environment Configuration

Copy `.env.production.example` to `.env.production`:

```bash
cp .env.production.example .env.production
```

Generate cryptographically secure secrets:

```bash
# Generate APP_SECRET
openssl rand -hex 16

# Generate POSTGRES_PASSWORD
openssl rand -hex 24
```

Update `.env.production` with these values and configure your production domain:

```ini
POSTGRES_DB=datapulse_prod
POSTGRES_USER=datapulse_prod_user
POSTGRES_PASSWORD=your_generated_postgres_password

APP_SECRET=your_generated_app_secret
APP_ENV=prod
CORS_ALLOW_ORIGIN=^https://app\.yourdomain\.com$
MAX_UPLOAD_SIZE=52428800

DATA_ENGINE_ENVIRONMENT=prod
MAX_PROFILABLE_BYTES=26214400

FRONTEND_PORT=80
```

### Step 2: Build & Launch with Docker Compose

Run the production compose file:

```bash
docker compose -f docker-compose.prod.yml --env-file .env.production up -d --build
```

Verify that all 4 containers are running and reported as healthy:

```bash
docker compose -f docker-compose.prod.yml ps
```

---

## 4. Health Checks & Verification

### Probing Endpoints

1. **Backend & Database Health Probe:**
   ```bash
   curl -s http://localhost/api/health | jq .
   ```
   *Expected response:*
   ```json
   {
     "status": "ok",
     "service": "datapulse-backend",
     "database": "ok"
   }
   ```

2. **Full End-to-End Smoke Test:**
   Execute the automated E2E test script to validate the entire platform flow (registration, authentication, file upload, profiling, cleaning, analytics, and AI analyst synthesis):

   ```bash
   python scripts/smoke_test.py --base-url http://localhost
   ```

---

## 5. Backup & Disaster Recovery

### Automated Database Backup

Create a timestamped PostgreSQL dump:

```bash
docker compose -f docker-compose.prod.yml exec -T postgres \
  pg_dump -U datapulse_prod_user datapulse_prod | gzip > backup_$(date +%Y%m%d_%H%M%S).sql.gz
```

### Database Restore

```bash
gunzip < backup_20261013_120000.sql.gz | docker compose -f docker-compose.prod.yml exec -T postgres \
  psql -U datapulse_prod_user -d datapulse_prod
```

### Dataset Storage Backup

Back up persistent datasets and JWT keys:

```bash
tar -czvf storage_backup_$(date +%Y%m%d).tar.gz /var/lib/docker/volumes/datapulse_ai_backend_storage
tar -czvf jwt_keys_backup_$(date +%Y%m%d).tar.gz /var/lib/docker/volumes/datapulse_ai_backend_jwt
```

---

## 6. Zero-Downtime Updates

To deploy an updated version with zero interruption:

```bash
# 1. Pull latest code
git pull origin master

# 2. Build updated images
docker compose -f docker-compose.prod.yml build

# 3. Apply rolling restart
docker compose -f docker-compose.prod.yml up -d --no-deps backend
docker compose -f docker-compose.prod.yml up -d --no-deps data-engine
docker compose -f docker-compose.prod.yml up -d --no-deps frontend
```
