const WAKE_TIMEOUT_MS = 90_000

/**
 * Knocks on each target from the operator's own browser before the API measures it.
 *
 * A sleeping free service on Render cannot be woken by another Render service: the edge
 * answers 429 to the request that would have started it, so the probe records "throttled"
 * and the target stays asleep. The same request from a laptop wakes it normally, so the
 * console does the knocking and lets the API do the measuring.
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
        mode: 'no-cors',
        cache: 'no-store',
        signal: AbortSignal.timeout(WAKE_TIMEOUT_MS),
      }),
    ),
  )
}
