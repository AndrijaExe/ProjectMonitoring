# Deploy to Render

[`render.yaml`](render.yaml) is a Blueprint: one file that creates the whole stack, so the
console is reachable from any browser instead of only from the laptop that ran `npm run dev`.

| Resource | Type | Role |
|---|---|---|
| `monitoring-api` | Web service, Docker | Symfony JSON API, Apache + PHP 8.4 |
| `monitoring-console` | Static site | The React build, with SPA fallback routing |
| `monitoring-db` | Postgres | Registry, probe history, metric samples |
| `monitoring-poll` | Cron, every 5 min | `app:poll-health`, so history fills without a visitor |

The cron job is the point of hosting this: probes run on a schedule, so the board shows a
real timeline rather than whatever the last person to open the page happened to trigger.

## First deploy

1. Push this repo to GitHub, then in Render: **New > Blueprint**, pick the repo, apply.
2. Choose a plan for `monitoring-db` when prompted. A free database is **deleted after 30
   days** and takes the probe history with it.
3. `CORS_ALLOWED_ORIGINS` is the one value Render cannot fill in, because the console URL
   does not exist until its first build. Leave it blank for now.
4. When both services are live, open `monitoring-console` and copy its URL, for example
   `https://monitoring-console.onrender.com`.
5. Paste it into `monitoring-api` > Environment > `CORS_ALLOWED_ORIGINS`, then save. Render
   redeploys the API. Until this is set, the browser blocks every API call and the login
   screen just says the token was rejected; the API logs a warning at startup for this.
6. Read `ADMIN_TOKEN` from `monitoring-api` > Environment. Render generated it, so it is a
   real secret and not the placeholder in `.env`. That value is the console password.

Everything else wires itself: `DATABASE_URL` comes from the database, and the console build
receives the API's public hostname as `API_HOST`, which `vite.config.ts` turns into
`VITE_API_BASE_URL`. No URL is hardcoded anywhere.

## Verify

```bash
curl -s https://monitoring-api.onrender.com/healthz   # {"status":"ok"}
curl -s https://monitoring-api.onrender.com/readyz    # {"status":"ready"} once the DB is reachable
```

Then sign in to the console and press **Poll all now**. If `/readyz` answers `not_ready`,
the API is up but cannot reach Postgres — check `DATABASE_URL` on the service.

## Send metrics from Loop 9

Render generated `LOOP9_INGEST_TOKEN` on `monitoring-api`. Read it there and give the same
value to the Loop 9 backend:

```bash
curl -sS -X POST https://monitoring-api.onrender.com/api/v1/projects/loop9/metrics \
  -H 'Content-Type: application/json' \
  -H "X-Ingest-Token: $LOOP9_INGEST_TOKEN" \
  -d '{"metrics":[{"name":"chat.requests","value":1}]}'
```

Rotating it means changing it in one place: the env var. The database stores only a
SHA-256 hash, and `app:db-setup` re-seeds the hash on every deploy.

## What the container does on boot

`backend/docker/entrypoint.sh` runs before Apache:

- Refuses to start in `prod` when `ADMIN_TOKEN` is missing, still the example value, or
  shorter than 16 characters. The image ships a `.env` with development defaults, and a
  public console guarded by a token from a public repository is worse than a failed deploy.
- Binds Apache to `$PORT`, which Render assigns at runtime.
- Runs `app:db-setup`, retrying for 30 seconds while the managed database wakes up. It is
  idempotent, so it works as a boot step on any plan instead of needing a pre-deploy hook.

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
