# ProjectMonitoring frontend

React + TypeScript operator console. State and HTTP live in Redux Toolkit Query so a new game does not need a new data layer.

## Layout

Mirrors the backend hexagon:

| Layer | Path | Responsibility |
|---|---|---|
| Model | `src/model` | Shared types and status helpers |
| Adapter / API | `src/adapter/api` | RTK Query endpoints |
| Adapter / store | `src/adapter/store` | Auth slice + store |
| Adapter / UI | `src/adapter/ui` | Pages and presentational components |

Add a feature by extending the model, an RTK endpoint, then a page. Keep HTTP out of components.

## Local run

From repo root, start the API first:

```bash
cd backend
composer install
php -S 127.0.0.1:8081 -t public
```

Then:

```bash
cd frontend
npm install
npm run dev
```

Open `http://127.0.0.1:5173` and sign in with `ADMIN_TOKEN` from `backend/.env` (default `change-me-admin-token`). Vite proxies `/api` to the Symfony server.

## Build and host

```bash
npm run build   # tsc -b && vite build, output in dist/
```

Set `VITE_API_BASE_URL` to the API origin when the SPA is not served behind the same host. See `.env.example`. Routing is client-side, so the host must serve `index.html` for unknown paths or `/projects/loop9` breaks on reload.
