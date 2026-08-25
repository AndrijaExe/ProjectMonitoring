import { TOKEN_STORAGE_KEY, type TokenStore } from '@shared/store/tokenPersistence'

/**
 * The browser's half of the token store: one tab, one session, gone when it closes.
 *
 * sessionStorage rather than localStorage because an admin token left behind on a shared
 * machine is worth more than the convenience of staying signed in.
 */
export const sessionTokenStore: TokenStore = {
  save(token) {
    sessionStorage.setItem(TOKEN_STORAGE_KEY, token)
  },
  clear() {
    sessionStorage.removeItem(TOKEN_STORAGE_KEY)
  },
}

export function readStoredToken(): string | null {
  return sessionStorage.getItem(TOKEN_STORAGE_KEY)
}
