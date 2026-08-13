# ProjectMonitoring

Monitoring-first admin console for Loop 9, with a project registry so the next game can plug in without a rewrite.

```
backend/   Symfony 8 JSON API on Postgres
frontend/  React + TypeScript + Redux Toolkit
```

Architecture: [ARCHITECTURE.md](ARCHITECTURE.md). Hosting it so the board is reachable from
anywhere, plus scheduled polling: [DEPLOY.md](DEPLOY.md).

## Local run

Start Postgres first (runs on port 5433 so it will not fight a local install):

```bash
docker compose up -d db
```

Terminal 1 — API:

```bash
cd backend
cp .env.dist .env.local   # if you do not already have one
# set ADMIN_TOKEN to a private 16+ character value
composer install
php bin/console app:db-setup   # creates tables, seeds the catalog, safe to re-run
php -S 127.0.0.1:8081 -t public
```

Terminal 2 — console:

```bash
cd frontend
npm install
npm run dev
```

Open `http://127.0.0.1:5173`, sign in with `ADMIN_TOKEN`, then **Poll all now**.

Loop 9 is seeded against `https://loop9-backend.onrender.com/healthz` and `/readyz`. Override `LOOP9_*` in `backend/.env.local` if the Render URL changes.

A target on a free hosting tier sleeps and answers slowly on the first request. The probe retries a timeout once and records `timeout` rather than `down`, so a slow wake-up is not reported as an outage. Give it more room with `PROBE_TIMEOUT_SECONDS` in `backend/.env.local`.

## Ingest a metric

```bash
curl -sS -X POST http://127.0.0.1:8081/api/v1/projects/loop9/metrics \
  -H 'Content-Type: application/json' \
  -H "X-Ingest-Token: $LOOP9_INGEST_TOKEN" \
  -d '{"metrics":[{"name":"chat.requests","value":1}]}'
```

## Poll from cron

```bash
cd backend
php bin/console app:poll-health
php bin/console app:poll-health --project=loop9
```

## Tests

The backend suite talks to the real database, so bring it up first:

```bash
docker compose up -d db
cd backend && composer test
cd frontend && npm run build
```

Tests use the separate `monitoring_test` database and truncate between cases, so they never touch your dev data.

## Deploy

Free end to end: `render.yaml` puts the API and the console on Render free instances, the
database is a Supabase project, and a GitHub Actions schedule polls every ten minutes,
which also keeps the API awake. Steps are in [DEPLOY.md](DEPLOY.md).

Browsers only reach the API from origins listed in `CORS_ALLOWED_ORIGINS`. Empty means
localhost only, which is what you want in development.

## Add another game later

Extend `ProjectCatalogSeeder` (or insert a row into `projects`) with `game_id`, health/ready URLs, and an ingest token hash. The dashboard and ingest API are already keyed by `game_id`.
