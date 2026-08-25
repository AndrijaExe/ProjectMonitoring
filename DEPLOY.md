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

The app talks to Postgres directly. It does not use the Supabase JS client or the anon key.
Tables still sit in `public`, which PostgREST exposes, so the schema enables row-level
security with no policies: the database owner (this API) keeps working, and anyone holding
only the project URL cannot read or write. That is what their "table publicly accessible"
mail is about. You can also turn the Data API off under Project Settings → API if you will
never use it.

## 2. Blueprint on Render

**New > Blueprint**, pick the repo, apply [`render.yaml`](render.yaml). Two values are
prompted for:

- `DATABASE_URL` — the string from step 1.
- `CORS_ALLOWED_ORIGINS` — the console URL, which does not exist yet. Enter
  `https://monitoring-console.onrender.com` as a best guess; step 3 confirms it.

Everything else wires itself: `ADMIN_TOKEN`, `ADMIN_READONLY_TOKEN` and `LOOP9_INGEST_TOKEN`
are generated (the second one is the phone app's, see section 9), and the
console build receives the API's hostname as `API_HOST`, which `vite.config.ts` turns into
the base URL. No URL is hardcoded in source.

One thing changed for consoles deployed before the phone app existed: the static site is now
built from the repository root instead of `frontend/`, because it shares its API client with the
phone app one level above. A blueprint sync applies that. If the console is instead wired by hand
in the dashboard, clear its root directory, set the build command to
`npm ci && npm run build --workspace frontend`, and publish `./frontend/dist`.

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

[`.github/workflows/poll.yml`](.github/workflows/poll.yml) calls `POST /api/v1/poll` every half
hour. Under Settings > Secrets and variables > Actions add:

- Variable `API_URL` — the API's own hostname, which is not a guess: Render appends a suffix
  when a service name is taken, so read it off the service page. Here it is
  `https://monitoring-api-0gy1.onrender.com`
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
  stays up for 15 minutes after its last request, so the monthly bill is a quarter of the
  number of polls. Only services on the free plan spend from that budget: a static site
  costs nothing, and a target on a paid plan is always awake anyway.

  With `monitoring-api` the only free service, an hourly poll costs about 180 hours and a
  half-hourly one about 360, which is where the cron sits — half of the allowance, and half
  an hour is also the longest a push alert can be delayed. Every twenty minutes would be 540.
  Anything under fifteen minutes never lets the API sleep at all, spends the whole 730 hour
  month and leaves no margin before Render suspends every free service in the workspace.
  Watch the number on the Billing page, and recount it whenever a target moves between plans.
- GitHub Actions minutes are free here because the repository is public. On a private one the
  half-hourly cron would cost about 1,440 of the 2,000 monthly minutes, since GitHub rounds
  every job up to a full minute.

### The dead man's switch

Do this one. Everything above can stop without a sound: a disabled schedule, a rotated
`ADMIN_TOKEN`, a suspended instance. No probe runs, so no status changes, so no alert is sent,
and the console keeps showing the last green board it saw. **The failure mode of a monitor is
silence, and silence is indistinguishable from good news.**

So something outside this repository has to expect the poll and complain when it does not
arrive:

1. Make a free check at [healthchecks.io](https://healthchecks.io). Period 1 hour, grace
   period 30 minutes, and your email as the notification method.
2. Copy its ping URL (`https://hc-ping.com/<uuid>`).
3. Add it under Settings > Secrets and variables > Actions as the secret `HEARTBEAT_URL`.

The workflow pings that URL after a successful poll, and pings `<url>/fail` when the poll
fails, so a broken run mails you at once instead of after the grace period. Without the secret
the step prints that nothing is watching and moves on, so the poll keeps working either way.

Test it the honest way: pause the check in healthchecks.io, or let the grace period lapse
without running the workflow, and confirm the mail arrives. A switch nobody has ever seen fire
is a guess.

The console covers the other half. `POLL_MAX_AGE_MINUTES` (default 120, two missed hours) makes
the fleet page say the schedule has stopped instead of quietly drawing yesterday's statuses.
That only helps when you are looking, which is why it is the second half and not the first.

## 5. Logs in the console (optional)

The project page can show the target's Render logs, so a red probe and the lines that explain
it live on the same screen. The fleet page shows the monitoring API's own logs, for the times
the suspect is the watcher rather than the game. Without a key both panels say so and nothing
else breaks.

1. Create a key at Account Settings > API Keys in the Render dashboard. It is shown once.
2. Add it to `monitoring-api` > Environment as `RENDER_API_KEY`, then redeploy.

Nothing else to configure: the API finds the service by the hostname already stored in the
project's health URL, so any target on `*.onrender.com` in the same workspace works. For its own
logs it uses `RENDER_SERVICE_ID`, which Render injects into every service. Both lookups are
cached for an hour.

Two limits are worth stating plainly:

- **A Render API key has no read-only mode.** It authorises everything you can do in the
  dashboard, deleting services included. It stays in the API's environment and is never sent to
  the browser, and the routes that use it are the log queries here plus the three buttons in
  section 8 — nothing else, whatever the key itself would allow. Treat it as a credential of the
  same weight as the database password.
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

The **Update status** button wakes the targets from your browser before the API probes them,
so a manual check during the quarter of an hour a free target spends asleep between runs reads
the game rather than the edge. That is why the button says "Waking…" first and can take most
of a minute.

Press **Test alert** on the fleet page once after setting this up. It sends a mail marked
`[test]` through the same code path a real alarm uses and reports back what Resend said, which
is the only way to learn that the setup works on a day when nothing is broken. Free instances
have no shell, so this is also the only way.

### Alarms from the game's own numbers

A service can answer every probe correctly while failing at its job: every AI call falling back
to a canned reply, a whole day passing with nobody playing, counters silently reverting to
memory. Once game counters are wired (section 7), four more alarms use them. Three need no
configuration because they fire on a change rather than a state:

| Alarm | Fires when | Why it cannot spam you |
|---|---|---|
| `quiet` | Nothing counted for 24h, after a day that did count | A game that never counted anything is unreleased, not quiet |
| `players.gone` | `players.online` falls from above zero to zero | Zero to zero is a Tuesday, not an event |
| `storage.memory` | The game reports it is counting in memory | It is a one-off condition, mailed once until fixed |
| `rate:<counter>` | A counter grows past its hourly ceiling | Only the counters you name below are watched |

The fourth needs a number, because only you know what "too many" means for your game. On
`monitoring-api` > Environment set `ALERT_RATE_PER_HOUR` to comma separated `name=limit` pairs:

```
api.errors=20,ai.failed=10,safety.unavailable=10,chat.denied.player_daily=0,chat.denied.player_monthly=0,abuse.watch=0
```

Loop 9 publishes `chat.messages`, `chat.denied`, `chat.denied.player_daily`, `abuse.watch`,
`api.errors`, `ai.fallback`, `ai.failed`, `safety.blocked`, `safety.unavailable`, `auth.issued`,
`auth.rejected` and `run.ended`, so any of those can be given a ceiling. A ceiling of `0` mails
on the first event in the hour — that is how a farmer hitting a quota shows up. A typo is ignored rather than fatal: a bad pair is dropped and
the poll still records health. Leave the variable empty and no rate alarm exists.

Each alarm is mailed once when it opens and once when it clears, the same as a probe alert, and
the state lives in the `metric_alarms` table. An alarm whose mail could not be delivered is not
recorded as raised, so it is retried on the next poll rather than lost.

Anything still raised is listed on the project page under **Open alarms**, with the time it
started, and counted as a chip on the fleet board. That is deliberate: a mail tells you what
changed at 3am, and nothing about whether it is still true when you sit down at nine.

## 7. Game counters (optional)

A probe says the web server answered. It says nothing about whether players are getting replies,
how often an AI provider falls over, or which endings runs reach. Loop 9 publishes those counts
at `GET /metrics`, and the monitor reads them each time a poll finds the game up.

1. Make one secret: `openssl rand -hex 32`.
2. On the **Loop 9** service > Environment, add it as `METRICS_TOKEN`, then redeploy.
3. On `monitoring-api` > Environment, add `METRICS_TOKENS` as `loop9=<the same secret>`.
   `LOOP9_METRICS_URL` is already set by the blueprint.

Without the token the game's endpoint returns 404 and the console shows probes only, which is
what it did before. Nothing else breaks.

Three things to expect the first day:

- **An empty panel is the normal state of a game nobody is playing.** The counts are of what
  players do: messages sent, logins, finished runs, errors. Probes are not players, so polling
  a healthy game all day adds nothing to them. `players online` appears next to the probe chips
  once somebody is in.
- **The first reading shows zeros.** Counters are lifetime totals, so "last 24 hours" is the
  difference between two readings and there is only one until the next poll. Growth appears
  from the second poll on.
- **A number can drop.** The game keeps its counters in Redis; if that key is lost the count
  restarts, and the console reads the new reading as everything counted since. It means the
  counter store was cleared, not that the game un-happened.
- **The Usage tab is the same scrape, not a billing API.** After Loop 9 is deployed with token
  counters, `ai.tokens.in` / `ai.tokens.out` / estimated spend appear there. Until then the tab
  says so rather than looking broken.

**`REDIS_URL` has to be set on Loop 9 for any of this to hold.** Without it the game counts in
the memory of one request, which is to say it does not count: every reading comes back empty and
players online stays at zero however many people are playing. The game reports which store it is
using, and the monitor writes a warning into its own log — visible under **Monitor logs** on the
fleet page — whenever the answer is memory.

Counters are read only when the health probe just came back `ok`. Asking a sleeping instance
would spend the timeout budget again to learn what the probe already reported, and that budget is
the thing keeping the free plan affordable.

## 8. Stop, start and rebuild from the console (optional)

The project page can show what the host says about the target's service and change it. Three
buttons, each undone by another one:

| Button | What it does | When it helps |
|---|---|---|
| **Rebuild** | Builds the current commit again and deploys it | A deploy that failed, or a build that went out with a bad cache |
| **Stop** | Suspends the service until someone starts it | The kill switch: nothing runs, nothing is spent |
| **Start** | Resumes a stopped service | Undoing the above |

It needs the key from section 5 plus one more variable. [`render.yaml`](render.yaml) now sets it,
so a blueprint sync is enough; a service deployed before that needs it added by hand on
`monitoring-api` > Environment:

```
CONTROLS_ENABLED=true
```

**Off by default, and the two switches are separate on purpose.** The key alone lets the panel
report the run state while the buttons stay out of reach, because wanting to read logs and being
willing to take a service down are not the same decision. With controls off, the panel says so
and everything else on the page still works.

What the panel adds beyond the probe chips is the one distinction probes cannot make: a stopped
service and a crashed one both fail every check, and only one of them is worth getting up for. So
it reads the host directly and says "stopped", "deploying now", or "the last deploy failed, so the
previous build is what is running", with the commit subject of what is live.

Expect this after pressing **Stop**, because it is honest rather than convenient:

- The next scheduled poll finds the target unreachable and mails you that it is down. A
  deliberate outage looks exactly like a real one from outside, and a monitor that trusted a flag
  over a measurement would be a worse monitor.
- A day later, the `quiet` alarm from section 6 fires too, for the same reason.
- Pressing **Start** produces the recovery mail, with the outage measured across your maintenance
  window.

Every press writes two lines into the API's own log — one when it is asked, one when the host
accepts — visible under **Monitor logs** on the fleet page. There is no record of *who* pressed
it: one shared `ADMIN_TOKEN` cannot tell two operators apart, and inventing a name would be worse
than admitting the limit.

Two things this does not do. It never chooses which code runs — no rollbacks, no branch or commit
picking — and it offers nothing that is not reversible by another button, so deleting a service,
editing its environment and changing its plan stay in the Render dashboard where they belong.
Render also rate limits these calls to ten a minute per service; the panel reports that as
something to wait out rather than as a failure.

For Loop 9 specifically, stopping the backend is safe in the way that matters: the game on Steam
runs without it. Players lose chat, Steam login and telemetry, and get connection errors in their
place, and no AI provider call can be made — which is where the real money goes. The softer brake
still exists and is not this button: `GAME_GLOBAL_DAILY_QUOTA` on Loop 9 caps paid work per day
while keeping logins and telemetry alive, at the cost of an environment change and a redeploy.

## 9. The board on a phone (optional)

`mobile/` is the same board, read-only, in Expo. It talks to the API deployed above and needs
nothing hosted of its own.

It signs in with `ADMIN_READONLY_TOKEN`, which [`render.yaml`](render.yaml) generates alongside
the admin token. Read it once from `monitoring-api` > Environment. A service deployed before that
line existed needs it added by hand — any private value of at least 16 characters; shorter counts
as unset and read-only access simply stays off.

**Why a second token.** This one reads the whole board and may ask for a fresh probe, and is
refused by everything else: stopping or rebuilding a service, clearing history, sending a test
alert. That is what makes it safe to keep on a device that leaves the house. Carrying the admin
token instead would mean the most exposed copy of the credential is also the most powerful one.

Probing is deliberately allowed. The schedule runs every half hour, so a phone opened in between
would otherwise show a board half an hour stale — a screenshot rather than a monitor. Pull down on
either screen and it wakes the targets from the phone first, then asks the API to measure, for the
reason in section 4.

Run it through Expo Go, which needs no build and no store:

```bash
npm install     # at the repository root
npm run mobile  # then scan the QR code with Expo Go
```

On the sign-in screen give it the API's URL — the real one from step 3, suffix included — and the
read-only token. Both are kept in the device keychain, so this is a one-off. Setting
`EXPO_PUBLIC_API_BASE_URL` in `mobile/.env.local` prefills the host; leaving it unset is the
safer default, since an app with the wrong hostname baked in would need rebuilding to fix.

For a standalone app on the phone rather than through Expo Go, build an APK. `npx eas build -p
android --profile preview` does it in Expo's cloud and needs an account; the same thing locally
needs a JDK 17 and the Android SDK, and no account:

```bash
cd mobile
npx expo prebuild -p android          # writes android/, which is gitignored and regenerable
cd android && ./gradlew assembleRelease -PreactNativeArchitectures=arm64-v8a
# app/build/outputs/apk/release/app-release.apk
```

Left alone, a release build packs all four CPU architectures and comes out at 73 MB. The flag
above keeps only `arm64-v8a`, which covers any phone from the last several years, and brings it
down to 28 MB. Pass it on the command line rather than editing `reactNativeArchitectures` in
`android/gradle.properties`: `prebuild` regenerates that file and would silently drop the change.

Use the SDK's own versions when adding libraries here — `npx expo install`, never plain
`npm install`. npm takes the newest release, which for `expo-secure-store` meant a package from an
older SDK line: it built cleanly and then died on launch looking for a class that
`expo-modules-core` no longer carries. `npx expo-doctor` catches that class of mismatch in a
second, and is worth running before every build.

It is signed with React Native's debug keystore, which the template wires into the release build
type. That is fine for a private app installed by hand — the phone will still warn about an
unknown source — and not fine for Play, which needs a keystore of your own. Nothing else in this
repository depends on either choice.

The header says which token is in use, `read-only` or `full access`, because the app accepts
either and knowing which one is on the phone matters. No CORS entry is needed: a native app is
not a browser origin.

## 10. Alerts on the phone (optional)

Every alert that goes to the inbox is also pushed to the phones that registered themselves, so
this section adds nothing to what gets said — only where it arrives. Nothing here is needed for
the board to work: without it the fleet screen says "alerts off" and gives the reason.

Android cannot deliver a push without Firebase Cloud Messaging, and that is true whether the send
goes through Expo or through Google directly. So three things have to line up, and all three live
outside this repository.

**One, an Expo project id.** From `mobile/`, `npx eas init` creates the project and writes
`extra.eas.projectId` into `app.json`. It needs a free Expo account. Without the id the app cannot
ask for a push token at all, because the token is issued against a project.

**Two, `google-services.json`.** In the [Firebase console](https://console.firebase.google.com)
create a project, add an **Android** app with the package name `com.andrija.monitoring` — it must
match `app.json` exactly or the build fails — and download the file into `mobile/`. It is
gitignored, and [`app.config.js`](mobile/app.config.js) wires it in only when it is there, so a
clone without it still builds a working board.

**Three, the FCM key uploaded to Expo.** In Firebase go to Project settings > Service accounts,
generate a private key, then from `mobile/` run `npx eas credentials`, pick Android, and upload it
as **FCM V1**. This is what lets Expo's push service speak to Google on your behalf. Skipping it
is the quiet failure mode: registration succeeds, the app reports alerts as on, and every send is
refused.

Then rebuild and reinstall the app — a push token is issued by native code, so Expo Go and an old
APK cannot get one for this setup.

Nothing needs configuring on the API. A phone posts its own token to `POST /api/v1/devices` on
each sign-in and the token is stored in `device_tokens`; signing out takes it off the list, and
any token Expo reports as `DeviceNotRegistered` is dropped on the next send. `EXPO_ACCESS_TOKEN`
exists for the day an Expo account turns on enhanced security for pushes, and is otherwise unset.

To check the whole path end to end, press **Send test alert** in the console. It goes through the
same fan-out as a real alert, so a phone that is set up correctly buzzes within seconds. Only the
full admin token may press it, which is why the phone cannot test itself.

Two limits worth knowing before trusting it:

- **An alert is only as fast as the poll.** Nothing watches continuously; the half-hourly schedule
  in section 4 is the ceiling on how late an outage can be reported, and section 4 explains why
  shortening it costs Render hours.
- **Dead tokens are noticed on a send, not before.** Expo reports `DeviceNotRegistered` in the
  ticket for the message that failed, so a phone that was reinstalled is dropped the first time an
  alert is sent to it rather than in advance. Reinstalling between alerts therefore leaves one
  stale row behind, which costs a refused message and nothing else.

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
