/**
 * Where the API lives, set once at start-up by whichever app is running.
 *
 * The web console reads it from the Vite build, the phone app from its Expo config. Neither
 * value is reachable from the other platform's bundler, so the client asks for it here rather
 * than reading an environment variable it cannot see.
 */
let baseUrl = ''

export function setApiBaseUrl(url: string): void {
  baseUrl = url.replace(/\/$/, '')
}

export function getApiBaseUrl(): string {
  return baseUrl
}
