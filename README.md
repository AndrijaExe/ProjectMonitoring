# ProjectMonitoring

Monitoring-first admin console for Loop 9, with a project registry so the next game can plug in without a rewrite.

```
backend/   Symfony 8 JSON API on Postgres
frontend/  React + TypeScript + Redux Toolkit — the console
mobile/    Expo + React Native — the same board, read-only, on a phone
shared/    API client, store and model, used by both clients
```

The repository root is an npm workspace, so `npm install` there installs both clients and
leaves one copy of React for `shared/` to borrow. See [shared/README.md](shared/README.md).

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
# set ADMIN_READONLY_TOKEN too if you want to try the phone app; empty means no read-only access
composer install
php bin/console app:db-setup   # creates tables, seeds the catalog, safe to re-run
php -S 127.0.0.1:8081 -t public
```

Terminal 2 — console:

```bash
npm install     # at the repository root, not in frontend/
npm run dev
```

Open `http://127.0.0.1:5173`, sign in with `ADMIN_TOKEN`, then **Poll all now**.

Terminal 3 — the phone app, optional:

```bash
npm run mobile   # or: npm start --workspace mobile
```

Scan the QR code with Expo Go. On the sign-in screen give it the API host your phone can reach
— `http://127.0.0.1:8081` is the laptop's own loopback and means nothing to a phone, so use the
machine's LAN address — and the read-only token.

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
npm run typecheck   # at the root: type-checks the phone app, then builds the console
npm run lint        # also at the root, so shared/ and the phone app are covered too
```

Tests use the separate `monitoring_test` database and truncate between cases, so they never touch your dev data.

## Deploy

Free end to end: `render.yaml` puts the API and the console on Render free instances, the
database is a Supabase project, and a GitHub Actions schedule polls every ten minutes,
which also keeps the API awake. Steps are in [DEPLOY.md](DEPLOY.md).

Browsers only reach the API from origins listed in `CORS_ALLOWED_ORIGINS`. Empty means
localhost only, which is what you want in development.

Setting `RENDER_API_KEY` adds a log panel to each project page, reading the target's Render
logs through the API so a failing probe and its cause sit on one screen. It is optional and
the key never leaves the server; see [DEPLOY.md](DEPLOY.md).

Adding `CONTROLS_ENABLED=true` on top of that key turns the project page's service panel from a
report into three buttons: rebuild, stop, start. The two switches are separate because reading
logs and taking a service down are not the same decision.

Setting `RESEND_API_KEY` and `ALERT_EMAIL_TO` turns state changes into email, so the board
stops being something you have to remember to open. Only transitions are sent, and readings
that mean "could not see the target" are never treated as either failure or recovery.

## Two levels of access

`ADMIN_TOKEN` reads the board and acts on it. `ADMIN_READONLY_TOKEN` reads the board and may
ask for a fresh probe, but is refused by anything that changes something: stopping or rebuilding
a service, clearing history, sending a test alert. Leaving it empty means there is no read-only
access at all.

The split exists so the phone app can carry a secret that costs nothing if the phone is lost.
Probing is deliberately on the reading side: without it a phone could only ever show whatever
the half-hourly schedule last wrote, which on a sleeping free instance is half an hour of nothing.

A refusal aimed at the read-only token answers `403` with `code: "FORBIDDEN"`, while a token
that is simply wrong answers `403` with `code: "UNAUTHORIZED"`. The clients sign out on the
second and not the first, so a read-only client cannot lock itself out of the board by asking
for something it was never allowed to do.

Read-only is header-only: it cannot sign into the console, which would otherwise show buttons
that every click would refuse.

## The phone app

`mobile/` is the same board, read-only, in Expo. Fleet overview, one project with health, usage
and logs, and pull-to-refresh that wakes the targets from the phone before asking the API to
probe them — the same order the console uses, and for the same reason.

It reuses `shared/`, so the API client, the store and the model are the console's, not a second
implementation that can drift. What it does not have is anything that acts: no service buttons,
no clear history, no test alert.

Alerts do reach it. Every alert that goes to the inbox is also pushed to the phones registered
with `/api/v1/devices`, which is the one write a read-only token may perform — asking to be told
that something broke acts on nothing. Push needs a Firebase project and an Expo project id that
cannot live in this repository; without them the app says "alerts off" on the fleet screen and
works as a board regardless. Step 10 of [DEPLOY.md](DEPLOY.md) has the walkthrough.

Running it on a device needs no CORS entry, because a native app is not a browser origin.

## Add another game later

Extend `ProjectCatalogSeeder` (or insert a row into `projects`) with `game_id`, health/ready URLs, and an ingest token hash. The dashboard and ingest API are already keyed by `game_id`.
