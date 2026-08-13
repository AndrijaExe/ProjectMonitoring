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
   is already taken across the platform, and `monitoring-api` in particular belongs to
   somebody else, so the API landed on `https://monitoring-api-0gy1.onrender.com` while the
   console kept `https://monitoring-console.onrender.com`. If the console URL differs from
   what you entered, fix `CORS_ALLOWED_ORIGINS` on `monitoring-api`. The console itself
   needs no correction: its build reads the API hostname from Render.
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
five minutes. Under Settings > Secrets and variables > Actions add:

- Variable `API_URL` — `https://monitoring-api.onrender.com`
- Variable `WARM_URLS` — space separated target URLs, e.g. `https://loop9-backend.onrender.com/healthz`
- Secret `ADMIN_TOKEN` — the generated value from step 3

Run it once by hand from the Actions tab to confirm. A green run prints `{"polled":2}`.

This does double duty: it fills the history while nobody is watching, and it keeps the free
instance awake, so the console usually loads without a cold start.

The run wakes the targets before asking the API to probe them, and that order is not
cosmetic. **A sleeping free service cannot be woken from another Render service.** The edge
answers `429` to the request that would have started it, so the probe never arrives and the
console can only record `throttled`. That is a trap rather than noise: once a target falls
asleep, the monitor can never wake it again by itself, and every later probe reports the same
thing until somebody loads the site from outside. The runner does not have that problem.

Two things that will eventually bite:

- **GitHub disables scheduled workflows after 60 days without repository activity.** If the
  board goes quiet, check the Actions tab first.
- **The poll interval is a budget, not a preference.** A workspace gets 750 free instance
  hours a month, shared by every free service in it, and a month is 730 hours. A service
  stays up for 15 minutes after its last request, so an hourly poll costs each service a
  quarter of the month, about 182 hours, and the API plus one game come to roughly 364.
  A five-minute poll never lets either sleep: 1460 hours, and Render suspends every free
  service in the workspace — the game included — until the next month. Shortening the cron
  means paying for one of the two services. Watch the number on the Billing page.
- GitHub Actions minutes are free here because the repository is public. On a private one,
  hourly runs are fine, but five-minute runs would eat the 2,000 monthly minutes, since
  GitHub rounds every job up to a full minute.

## 5. Logs in the console (optional)

The project page can show the target's Render logs, so a red probe and the lines that explain
it live on the same screen. Without a key the panel says so and nothing else breaks.

1. Create a key at Account Settings > API Keys in the Render dashboard. It is shown once.
2. Add it to `monitoring-api` > Environment as `RENDER_API_KEY`, then redeploy.

Nothing else to configure: the API finds the service by the hostname already stored in the
project's health URL, so any target on `*.onrender.com` in the same workspace works, including
`monitoring-api` itself. The lookup result is cached for an hour.

Two limits are worth stating plainly:

- **A Render API key has no read-only mode.** It authorises everything you can do in the
  dashboard, deleting services included. It stays in the API's environment and is never sent
  to the browser, and the API exposes no route that does anything with it beyond reading logs,
  so what an admin-token holder can reach is limited to the panel. Treat the key as a
  credential of the same weight as the database password anyway.
- Free plans keep only a short window of logs, and the panel asks for the last 24 hours. For
  anything older, use the Render dashboard.

Supabase logs are deliberately not wired up: their free tier keeps one day of Postgres logs
behind a separate management token, and the database is quiet enough that the probe history
already tells that story.

## 6. Email alerts (optional)

Without this the console only helps while somebody is looking at it. With it, a fall from
healthy to broken arrives as mail, and so does the recovery, with the outage duration.

1. Create an account at [resend.com](https://resend.com) and an API key.
2. On `monitoring-api` > Environment add `RESEND_API_KEY` and `ALERT_EMAIL_TO` (your
   address). Leave `ALERT_EMAIL_FROM` as `onboarding@resend.dev` until you own a domain —
   that sender only delivers to the address the Resend account was registered with, which
   is exactly the case here.

**This goes over HTTPS, not SMTP, and that is not a preference.** Since September 2025 Render
blocks outbound traffic to ports 25, 465 and 587 on free web services, so a normal mailer
would work on your laptop and time out in production, with nothing in the logs to explain it.
Port 443 is open on every plan.

What triggers a message:

- Only transitions. A target that has been down for a day produces one mail, not one an hour.
- Only conclusive readings. `throttled` and `timeout` mean the probe could not see the target,
  which is what a sleeping free instance looks like, so they neither raise an alarm nor count
  as a recovery. An outage that began before we went blind is still measured from the real
  failure.
- The first ever reading, if it is bad. A monitor that stays silent because it has no history
  is useless on the day you set it up.

Alert delivery never fails a poll. If Resend is unreachable the snapshot is still recorded and
a warning goes to the service log.

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

**When the game goes to production, pay for the game first, not for the monitor.** A paid
Loop 9 never sleeps, which is what players need anyway, and it stops drawing on the free
instance hours. That frees the whole 750 for `monitoring-api`, so two things become possible
at once: the cron in [`poll.yml`](.github/workflows/poll.yml) can go back to `*/5 * * * *`
for five-minute history, and the wake step stops mattering, since a paid service is always
up. Leave `WARM_URLS` set regardless — it costs nothing and still covers any free target
added later. The margin is thinner than it looks: one free service awake around the clock is
730 hours against 750, so a second one still will not fit.

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
