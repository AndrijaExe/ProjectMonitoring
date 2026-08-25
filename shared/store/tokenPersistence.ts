import type { AppStore } from './store'

/**
 * Where a platform keeps the token between runs. The browser has sessionStorage, the phone has
 * a keychain that answers with promises, so both shapes are allowed.
 */
export type TokenStore = {
  save(token: string): void | Promise<void>
  clear(): void | Promise<void>
}

export const TOKEN_STORAGE_KEY = 'pm.adminToken'

/**
 * Writes the token out whenever it changes, so the slice never has to know what storage looks
 * like on the platform it is running on.
 *
 * Returns the unsubscribe function, which an app that lives for one store does not need.
 */
export function persistToken(store: AppStore, storage: TokenStore): () => void {
  let last = store.getState().auth.token

  return store.subscribe(() => {
    const next = store.getState().auth.token
    if (next === last) {
      return
    }
    last = next

    // Nothing waits on this, and a keychain that refuses to write is not worth interrupting an
    // operator over: the token still works for as long as the app is open.
    void Promise.resolve(next === null ? storage.clear() : storage.save(next)).catch(() => {})
  })
}
