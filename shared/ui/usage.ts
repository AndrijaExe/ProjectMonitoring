import type { UsageDay, UsageProvider } from '../model/monitoring'

/**
 * Reading the game's AI spend, without the drawing.
 *
 * Both clients show these numbers and neither should be free to round them its own way: a
 * phone and a console disagreeing about a bill is worse than either being slightly off.
 */

/** Counters worth naming next to the spend, in the order they read best. */
export const USAGE_RELATED: { name: string; label: string }[] = [
  { name: 'chat.messages', label: 'chat replies' },
  { name: 'ai.fallback', label: 'fell back to another provider' },
  { name: 'ai.failed', label: 'provider failures' },
  { name: 'safety.blocked', label: 'blocked by moderation' },
  { name: 'safety.unavailable', label: 'moderation unavailable' },
]

export const USAGE_FAIR_USE: { name: string; label: string }[] = [
  { name: 'abuse.watch', label: 'players who crossed the daily watch line' },
  { name: 'chat.denied.player_daily', label: 'hit the daily player cap' },
  { name: 'chat.denied.player_monthly', label: 'hit the monthly player cap' },
  { name: 'chat.denied.ip_daily', label: 'hit the daily IP cap' },
  { name: 'chat.denied.global', label: 'hit the global daily cap' },
  { name: 'chat.denied.burst', label: 'burst-limited' },
]

export const USAGE_COLORS = ['#c6f54a', '#6ec8ff', '#f0c14a', '#d4a5ff', '#7f9486']

/** A stable colour per provider, so the same host is the same colour on both clients. */
export function usageColorFor(id: string): string {
  const known: Record<string, string> = {
    openai: USAGE_COLORS[0],
    gemini: USAGE_COLORS[1],
    groq: USAGE_COLORS[2],
    all: USAGE_COLORS[0],
    unknown: USAGE_COLORS[4],
  }

  if (known[id]) {
    return known[id]
  }

  let hash = 0
  for (const char of id) {
    hash = (hash + char.charCodeAt(0)) % USAGE_COLORS.length
  }

  return USAGE_COLORS[hash]
}

export function uniqueProviders(days: UsageDay[]): UsageProvider[] {
  const seen = new Map<string, UsageProvider>()
  for (const day of days) {
    for (const provider of day.providers) {
      if (!seen.has(provider.id)) {
        seen.set(provider.id, provider)
      }
    }
  }

  return [...seen.values()]
}

export function valueFor(day: UsageDay, provider: UsageProvider, useCost: boolean): number {
  const row = day.providers.find((item) => item.id === provider.id)
  if (!row) {
    return 0
  }

  return useCost ? row.cost_micros : row.tokens_in + row.tokens_out
}

export function hasDayActivity(day: UsageDay): boolean {
  return day.tokens_in > 0 || day.tokens_out > 0 || day.cost_micros > 0
}

export function formatDay(isoDate: string): string {
  const [year, month, day] = isoDate.split('-').map(Number)
  return new Date(Date.UTC(year, month - 1, day)).toLocaleDateString('en-GB', {
    day: 'numeric',
    month: 'short',
    timeZone: 'UTC',
  })
}

export function formatCount(value: number | undefined): string {
  return value == null ? '—' : Math.round(value).toLocaleString('en-US')
}

/**
 * Micros to dollars. Sub-cent amounts keep their digits: early on the whole bill is a fraction
 * of a cent, and rounding it to $0.00 would read as "nothing is being spent".
 */
export function formatUsd(micros: number | undefined): string {
  if (micros == null) {
    return '—'
  }

  const usd = micros / 1_000_000
  if (usd === 0) {
    return '$0'
  }

  if (usd < 0.01) {
    return `$${usd.toFixed(6).replace(/\.?0+$/, '')}`
  }

  return `$${usd.toFixed(2)}`
}
