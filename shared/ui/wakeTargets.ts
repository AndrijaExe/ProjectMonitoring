const WAKE_TIMEOUT_MS = 90_000

/**
 * A signal that gives up after ms.
 *
 * AbortSignal.timeout is the short way to write this, but it is missing on some of the
 * JavaScript engines this code runs on, so the long way is kept as a fallback.
 */
function timeoutSignal(ms: number): AbortSignal | undefined {
  if (typeof AbortSignal !== 'undefined' && typeof AbortSignal.timeout === 'function') {
    return AbortSignal.timeout(ms)
  }
  if (typeof AbortController === 'undefined') {
    return undefined
  }

  const controller = new AbortController()
  setTimeout(() => controller.abort(), ms)

  return controller.signal
}

/**
 * Knocks on each target from the operator's own device before the API measures it.
 *
 * A sleeping free service on Render cannot be woken by another Render service: the edge
 * answers 429 to the request that would have started it, so the probe records "throttled"
 * and the target stays asleep. The same request from a laptop or a phone wakes it normally,
 * so the client does the knocking and lets the API do the measuring.
 *
 * The response is opaque and deliberately ignored. Only the arrival matters, and waiting for
 * it is what gives the target time to boot before the probe goes out.
 */
export async function wakeTargets(urls: string[]): Promise<void> {
  const origins = new Map<string, string>()
  for (const url of urls) {
    try {
      origins.set(new URL(url).origin, url)
    } catch {
      // A malformed URL is the probe's problem to report, not something to wake.
    }
  }

  await Promise.allSettled(
    [...origins.values()].map((url) =>
      fetch(url, {
        // Both are no-ops off the web, where the request is not subject to CORS anyway.
        mode: 'no-cors',
        cache: 'no-store',
        signal: timeoutSignal(WAKE_TIMEOUT_MS),
      }),
    ),
  )
}
