import * as SecureStore from 'expo-secure-store'
import { TOKEN_STORAGE_KEY, type TokenStore } from '@shared/store/tokenPersistence'

// SecureStore keys allow letters, digits, dot, dash and underscore, which the shared key already
// satisfies. The host is kept beside it: it is not a secret, but it is the other half of what
// makes the app usable, and there is nothing else on the device worth writing it to.
const HOST_KEY = 'pm.apiBaseUrl'

/**
 * The phone's half of the token store: the device keychain, which survives a restart.
 *
 * Unlike the console, which forgets the token when the tab closes, a phone in a pocket is the
 * operator's own and being asked to retype a long secret to glance at the board would mean
 * never glancing at it.
 */
export const secureTokenStore: TokenStore = {
  async save(token) {
    await SecureStore.setItemAsync(TOKEN_STORAGE_KEY, token)
  },
  async clear() {
    await SecureStore.deleteItemAsync(TOKEN_STORAGE_KEY)
  },
}

export async function readStoredToken(): Promise<string | null> {
  return safely(() => SecureStore.getItemAsync(TOKEN_STORAGE_KEY))
}

export async function readStoredHost(): Promise<string | null> {
  return safely(() => SecureStore.getItemAsync(HOST_KEY))
}

export async function storeHost(host: string): Promise<void> {
  await SecureStore.setItemAsync(HOST_KEY, host)
}

/**
 * A keychain that will not open is the same situation as having nothing stored: the operator
 * signs in again. Worth no more than that, and certainly not a crash at start-up.
 */
async function safely(read: () => Promise<string | null>): Promise<string | null> {
  try {
    return await read()
  } catch {
    return null
  }
}
