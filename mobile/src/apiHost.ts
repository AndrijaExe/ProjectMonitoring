import { setApiBaseUrl } from '@shared/api/config'
import { readStoredHost, storeHost } from './secureStorage'

/**
 * A build-time default, for a build made for one deployment.
 *
 * Left empty it costs nothing: the operator types the host once on the sign-in screen. That
 * fallback is not a convenience, it is the point. Render appends a suffix when a service name
 * is taken, so the API's real hostname is not something a build can be sure of, and an app
 * that hardcoded the wrong one would need rebuilding to reach the right one.
 */
const buildTimeDefault = (process.env.EXPO_PUBLIC_API_BASE_URL ?? '').replace(/\/$/, '')

let current = buildTimeDefault

export function currentApiBaseUrl(): string {
  return current
}

/** Reads the host chosen last time, and points the shared client at it. */
export async function restoreApiBaseUrl(): Promise<void> {
  const stored = await readStoredHost()
  current = stored ?? buildTimeDefault
  setApiBaseUrl(current)
}

/** Takes the host the operator typed, for this run and the next. */
export async function applyApiBaseUrl(url: string): Promise<void> {
  current = url.trim().replace(/\/$/, '')
  setApiBaseUrl(current)

  try {
    await storeHost(current)
  } catch {
    // Worth no interruption: the host is in memory for this run, and the sign-in screen is
    // where it gets asked for again.
  }
}
