# Deploy

The console runs on free infrastructure. Render charges for Postgres and has no free cron,
so those two pieces live elsewhere:

| Piece | Where | Cost |
|---|---|---|
| API (`monitoring-api`) | Render web service, Docker, free instance | $0 |
| Console (`monitoring-console`) | Render static site | $0 |
| Database | Supabase Postgres, free project | $0 |
| Scheduled polling | GitHub Actions, every 10 minutes | $0 |

Moving the frontend off Render would save nothing — static sites are already free. The cost
was the API instance and the managed database, so those are what changed.

## 1. Database on Supabase

Create a project, then **Connect** and copy a **pooler** connection string. Not the direct
one: Supabase's direct host is IPv6 only, and Render dials out over IPv4. Either pooler
port works — the app detects port `6543` (or `?pgbouncer=true`) and switches PDO to
client-side prepared statements, which is what a transaction pooler requires.

```
postgresql://postgres.abcdefgh:PASSWORD@aws-0-eu-central-1.pooler.supabase.com:6543/postgres?sslmode=require
```

Substitute the real password. Keep `sslmode=require`; Supabase refuses plaintext.

## 2. Blueprint on Render

**New > Blueprint**, pick the repo, apply [`render.yaml`](render.yaml). Two values are
prompted for:

- `DATABASE_URL` — the string from step 1.
- `CORS_ALLOWED_ORIGINS` — the console URL, which does not exist yet. Enter
  `https://monitoring-console.onrender.com` as a best guess; step 3 confirms it.

Everything else wires itself: `ADMIN_TOKEN` and `LOOP9_INGEST_TOKEN` are generated, and the
console build receives the API's hostname as `API_HOST`, which `vite.config.ts` turns into
the base URL. No URL is hardcoded in source.

## 3. After the first deploy

1. Open `monitoring-console` and check its real URL. Render appends a suffix when the name
   is taken, so it may be `monitoring-console-a1b2.onrender.com`. If it differs from what
   you entered, fix `CORS_ALLOWED_ORIGINS` on `monitoring-api`.
2. Read `ADMIN_TOKEN` from `monitoring-api` > Environment. That is the console password.
3. Check the API answers:

```bash
curl -s https://monitoring-api.onrender.com/healthz   # {"status":"ok"}
curl -s https://monitoring-api.onrender.com/readyz    # {"status":"ready"} once the DB is reachable
```

`not_ready` means the API is up but cannot reach Supabase — almost always a wrong password
or the direct (IPv6) host instead of the pooler.

If CORS is wrong, the symptom misleads: the console loads fine and login says the token was
rejected, because the browser blocked the call before it reached the API. The API logs a
warning at startup when the variable is empty.

## 4. Scheduled polling

[`.github/workflows/poll.yml`](.github/workflows/poll.yml) calls `POST /api/v1/poll` every
ten minutes. In the GitHub repo settings add:

- Variable `API_URL` — `https://monitoring-api.onrender.com`
- Secret `ADMIN_TOKEN` — the generated value from step 3

Run it once by hand from the Actions tab to confirm. A green run prints `{"polled":2}`.

This does double duty: it fills the history while nobody is watching, and it keeps the free
instance awake, so the console usually loads without a cold start.

Two things that will eventually bite:

- **GitHub disables scheduled workflows after 60 days without repository activity.** If the
  board goes quiet, check the Actions tab first.
- Ten-minute polling keeps the instance running roughly 24/7, and Render's free tier
  allows 750 instance hours a month against a month's 730. One service fits; a second free
  service would not.

## Free tier limits worth knowing

- The API sleeps after 15 minutes without traffic and takes close to a minute to wake. The
  poll normally prevents this, but the first load after a quiet stretch can be slow.
- Supabase pauses a free project after 7 days of inactivity. The ten-minute poll counts as
  activity, so this only matters if polling stops.
- Supabase free gives 500 MB. Snapshots are small — roughly ten megabytes a year at this
  cadence — but nothing prunes old rows yet, so that is the first thing to add if the
  project ever runs for years.
- Free instances have no shell access and no persistent disk. Neither matters here: all
  state is in Postgres.

## Paying to remove the annoyance

If cold starts get irritating, switch `plan: free` to `plan: starter` on `monitoring-api`
($7/month) and the instance stays warm. That is the only change; the database and the
schedule can stay where they are.

Going back to fully managed Render Postgres means adding a `databases:` block and pointing
`DATABASE_URL` at it with `fromDatabase`. Their cron jobs bill by the second with a $1
monthly minimum per job, so the GitHub Actions schedule stays cheaper regardless.

## What the container does on boot

`backend/docker/entrypoint.sh` runs before Apache:

- Refuses to start in `prod` when `ADMIN_TOKEN` is missing, still the example value, or
  shorter than 16 characters. The image ships a `.env` with development defaults, and a
  public console guarded by a token from a public repository is worse than a failed deploy.
- Binds Apache to `$PORT`, which Render assigns at runtime.
- Runs `app:db-setup`, retrying for 30 seconds while the database wakes up. It is
  idempotent, so it works as a boot step instead of needing a paid pre-deploy hook.

## Send metrics from Loop 9

Render generated `LOOP9_INGEST_TOKEN` on `monitoring-api`. Give the same value to the Loop 9
backend:

```bash
curl -sS -X POST https://monitoring-api.onrender.com/api/v1/projects/loop9/metrics \
  -H 'Content-Type: application/json' \
  -H "X-Ingest-Token: $LOOP9_INGEST_TOKEN" \
  -d '{"metrics":[{"name":"chat.requests","value":1}]}'
```

The database stores only a SHA-256 hash, and `app:db-setup` re-seeds it on every deploy, so
rotating the token means changing one environment variable.

## Try the production image locally

Same image Render builds, pointed at the compose database:

```bash
docker compose up -d db
docker build -t projectmonitoring-api:local backend
docker run --rm -p 8099:8099 --network projectmonitoring_default \
  -e PORT=8099 -e APP_ENV=prod \
  -e APP_SECRET=local-secret \
  -e ADMIN_TOKEN=local-verification-token-1234 \
  -e DATABASE_URL='postgresql://monitoring:monitoring@db:5432/monitoring' \
  -e CORS_ALLOWED_ORIGINS='http://127.0.0.1:5173' \
  projectmonitoring-api:local
```

## Custom domain

Add it to `monitoring-console` in the dashboard, then append the new origin to
`CORS_ALLOWED_ORIGINS` (comma separated — both origins can stay listed).

## Worth knowing before this is public

- The console holds one shared admin token in `sessionStorage` and sends it as
  `X-Admin-Token`. Fine for a solo operator over HTTPS; if this ever grows to real
  accounts, that is the piece to replace, not the persistence layer.
- Unlisted origins receive no `Access-Control-Allow-Origin` header at all. There is no
  wildcard fallback, so an unconfigured deploy fails closed rather than open.
- `APP_ENV=prod` and `APP_DEBUG=0` are set in the Blueprint, so no stack traces leak.
- Login is not rate limited. A generated 32-byte token is not guessable, but a public
  endpoint that checks secrets is the natural place to add throttling later.
