# shared

The parts of the client that both the web console and the phone app need: the API client, the
Redux store, the model of what the API returns, and a few hooks and formatters.

## The rule

Nothing in here may touch a platform API. No `window`, no `sessionStorage`, no `document`, no
`import.meta.env`, no React Native import. `fetch`, `URL` and `AbortController` are fine because
both platforms have them.

Two things used to break that rule and now take the platform's answer as an argument instead:

- **Where the API lives.** `api/config.ts` holds it, and each app calls `setApiBaseUrl` at
  start-up: the console from its Vite build, the phone app from its Expo config.
- **Where the token is kept.** `store/authSlice.ts` only holds the token in memory.
  `store/tokenPersistence.ts` writes it out through a `TokenStore` the app provides:
  `sessionStorage` on the web, the device keychain on the phone.

## How each app reaches it

There is no build step and no package to publish. Both apps alias `@shared/*` to this folder:

- The console: `resolve.alias` in `frontend/vite.config.ts`, `paths` in `tsconfig.app.json`.
- The phone app: `extraNodeModules` and `watchFolders` in `mobile/metro.config.js`, `paths` in
  `mobile/tsconfig.json`.

React and Redux are not declared here. These files borrow whichever copy the running app
installed, which is why the repository root is an npm workspace.
