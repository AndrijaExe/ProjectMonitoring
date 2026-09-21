import type { ReportBucket, ReportPeriod } from '../model/monitoring'

/**
 * Reading a period report, without the drawing. Both clients group and label buckets from
 * here, so a week is the same week on the phone and on the console.
 */

/** The counters worth a tile of their own, in the order an operator asks about them. */
export const HEADLINE_COUNTERS: {
  key: string
  label: string
  /** More is good, or more is bad — decides the colour of the change against last period. */
  goodWhen: 'up' | 'down'
  /** Several series summed under one tile. */
  names: string[]
  money?: boolean
}[] = [
  { key: 'sessions', label: 'Sessions', goodWhen: 'up', names: ['auth.issued'] },
  { key: 'runs', label: 'Runs finished', goodWhen: 'up', names: ['run.ended'] },
  { key: 'chats', label: 'Chat replies', goodWhen: 'up', names: ['chat.messages'] },
  { key: 'spend', label: 'AI spend', goodWhen: 'down', names: ['ai.cost.micros'], money: true },
  {
    key: 'problems',
    label: 'Problems',
    goodWhen: 'down',
    names: ['api.errors', 'ai.failed', 'safety.unavailable'],
  },
]

/** Plain words for the series the game publishes; anything else is prettified generically. */
const LABELS: Record<string, string> = {
  'auth.issued': 'Sessions started',
  'auth.rejected': 'Sign-ins refused',
  'chat.messages': 'Chat replies',
  'chat.denied': 'Chats refused',
  'run.ended': 'Runs finished',
  'run.ended.escape_together': 'Escape Together',
  'run.ended.obedient_fool': 'Obedient Fool',
  'run.ended.cold_betrayal': 'Cold Betrayal',
  'run.ended.paranoid_survivor': 'Paranoid Survivor',
  'run.ended.merged_memory': 'Merged Memory',
  'run.ended.the_replacement': 'The Replacement',
  'run.ended.the_exit': 'The Exit',
  'run.commitment.location_misdirection': 'Planted a false location',
  'run.commitment.decoy_visited': 'Player checked the false location',
  'run.commitment.contradiction_exposed': 'Player caught the lie',
  'run.commitment.wrong_lift_advised': 'Advised the wrong lift',
  'run.commitment.wrong_lift_followed': 'Wrong lift was followed',
  'run.commitment.stale_floor': 'Described the previous floor',
  'ai.fallback': 'Fell back to another provider',
  'ai.failed': 'Provider failures',
  'ai.tokens.in': 'Tokens in',
  'ai.tokens.out': 'Tokens out',
  'ai.cost.micros': 'AI spend',
  'api.errors': 'API errors',
  'safety.blocked': 'Blocked by moderation',
  'safety.unavailable': 'Moderation unavailable',
  'abuse.watch': 'Crossed the daily watch line',
  'players.online': 'players online',
  'players.day': 'players today',
}

/** Groups, in reading order; a series lands in the first group whose prefix matches. */
export const COUNTER_GROUPS: { id: string; label: string; prefixes: string[] }[] = [
  { id: 'players', label: 'Players', prefixes: ['auth.', 'run.ended', 'chat.messages'] },
  { id: 'endings', label: 'Endings', prefixes: ['run.ended.'] },
  { id: 'dragojlo', label: 'Dragojlo', prefixes: ['run.commitment.', 'advice.', 'run.relationship.'] },
  { id: 'ai', label: 'AI', prefixes: ['ai.'] },
  { id: 'safety', label: 'Safety and limits', prefixes: ['safety.', 'chat.denied', 'abuse.'] },
  { id: 'errors', label: 'Errors', prefixes: ['api.'] },
  { id: 'other', label: 'Other', prefixes: [] },
]

export function labelFor(name: string): string {
  if (LABELS[name]) {
    return LABELS[name]
  }

  // Families the backend grows on its own: the tail is a closed word list, so it reads fine.
  const words = (tail: string) => tail.replace(/[._]+/g, ' ')
  if (name.startsWith('advice.mode.')) {
    return `Advice: ${words(name.slice('advice.mode.'.length))}`
  }
  if (name.startsWith('advice.gate.wrong_lift.')) {
    return `Wrong-lift gate: ${words(name.slice('advice.gate.wrong_lift.'.length))}`
  }
  if (name.startsWith('advice.gate.misdirect.')) {
    return `Misdirect gate: ${words(name.slice('advice.gate.misdirect.'.length))}`
  }
  if (name.startsWith('run.relationship.')) {
    return `Relationship: ${words(name.slice('run.relationship.'.length))}`
  }
  if (name.startsWith('chat.denied.')) {
    return `Chats refused: ${words(name.slice('chat.denied.'.length))}`
  }

  // `advice.gate.wrong_lift.dependency` → "advice gate wrong lift dependency"; readable, and
  // still searchable by the raw name in the title attribute.
  return name.replace(/[._]+/g, ' ')
}

export function groupFor(name: string): string {
  // The endings group must win over the players group for `run.ended.<x>`.
  if (name.startsWith('run.ended.')) {
    return 'endings'
  }
  for (const group of COUNTER_GROUPS) {
    if (group.prefixes.some((prefix) => name.startsWith(prefix))) {
      return group.id
    }
  }

  return 'other'
}

export function sumOf(bucket: ReportBucket | undefined, names: string[]): number {
  if (!bucket) {
    return 0
  }

  return names.reduce((sum, name) => sum + (bucket.totals[name] ?? 0), 0)
}

/**
 * The per-provider copies of the AI series (`ai.tokens.in.openai`) repeat the totals above
 * them; the Usage tab already splits by provider, so the counter table leaves them out.
 */
export function isProviderCopy(name: string): boolean {
  return /^ai\.(tokens\.(in|out)|cost\.micros)\.[^.]+$/.test(name)
}

/** Every series any bucket has seen, so a column of zeros is still a column. */
export function seriesIn(buckets: ReportBucket[]): string[] {
  const names = new Set<string>()
  for (const bucket of buckets) {
    for (const name of Object.keys(bucket.totals)) {
      names.add(name)
    }
  }

  return [...names]
}

/**
 * How much of a bucket has passed, 0–1. A Monday against a whole previous week reads as a
 * collapse, so the comparison below scales the previous bucket to the same elapsed share.
 */
export function elapsedShare(bucket: ReportBucket | undefined, now: number = Date.now()): number {
  if (!bucket || bucket.complete) {
    return 1
  }

  const start = Date.parse(bucket.start)
  const end = Date.parse(bucket.end)
  if (Number.isNaN(start) || Number.isNaN(end) || end <= start) {
    return 1
  }

  return Math.min(1, Math.max(0, (now - start) / (end - start)))
}

/**
 * "vs last week at this point" as a signed share; null when there is nothing to compare
 * against. `share` is how far the current bucket has got, so the previous one is pro-rated.
 */
export function changeBetween(current: number, previous: number, share: number = 1): number | null {
  const expected = previous * share
  if (expected <= 0) {
    return null
  }

  return (current - expected) / expected
}

export function formatChange(change: number | null): string {
  if (change == null) {
    return ''
  }

  const percent = Math.round(change * 100)
  if (percent === 0) {
    return '±0%'
  }

  return `${percent > 0 ? '+' : ''}${percent}%`
}

export function periodNoun(period: ReportPeriod): string {
  return period === 'day' ? 'day' : period === 'week' ? 'week' : 'month'
}

/** "Today" / "This week" / "This month" for the bucket in progress. */
export function currentLabel(period: ReportPeriod): string {
  return period === 'day' ? 'Today' : period === 'week' ? 'This week' : 'This month'
}

export function previousLabel(period: ReportPeriod): string {
  return period === 'day' ? 'yesterday' : period === 'week' ? 'last week' : 'last month'
}

/** The comparison line under a tile, honest about a bucket that is still filling. */
export function comparisonLabel(period: ReportPeriod, share: number): string {
  return share < 1 ? `vs ${previousLabel(period)} at this point` : `vs ${previousLabel(period)}`
}

/** The shortest label that still tells buckets apart, for a crowded axis. */
export function axisLabel(bucket: ReportBucket, period: ReportPeriod): string {
  const start = new Date(bucket.start)
  if (period === 'week') {
    return `W${bucket.key.slice(-2)}`
  }
  if (period === 'month') {
    return start.toLocaleDateString('en-GB', { month: 'short', timeZone: 'UTC' })
  }

  return start.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', timeZone: 'UTC' })
}

/** A short label for a bucket, for column heads and tables. */
export function bucketLabel(bucket: ReportBucket, period: ReportPeriod): string {
  const start = new Date(bucket.start)
  if (period === 'day') {
    return start.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', timeZone: 'UTC' })
  }
  if (period === 'week') {
    return `W${bucket.key.slice(-2)} · ${start.toLocaleDateString('en-GB', {
      day: 'numeric',
      month: 'short',
      timeZone: 'UTC',
    })}`
  }

  return start.toLocaleDateString('en-GB', { month: 'short', year: 'numeric', timeZone: 'UTC' })
}

/** The whole span a bucket covers, for a tooltip or a caption. */
export function bucketSpan(bucket: ReportBucket, period: ReportPeriod): string {
  const start = new Date(bucket.start)
  const end = new Date(new Date(bucket.end).getTime() - 1)
  const fmt = (date: Date) =>
    date.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' })

  if (period === 'day') {
    return fmt(start)
  }

  return `${fmt(start)} – ${fmt(end)}`
}

/** How many columns the table shows for a period; the chart shows every bucket regardless. */
export function tableColumns(period: ReportPeriod): number {
  return period === 'day' ? 7 : period === 'week' ? 8 : 6
}
