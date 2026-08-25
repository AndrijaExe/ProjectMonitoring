export type HealthStatus =
  | 'ok'
  | 'ready'
  | 'not_ready'
  | 'down'
  | 'timeout'
  | 'throttled'
  | 'error'
  | 'unseen'

export type ProbeSnapshot = {
  status: HealthStatus | null
  http_code: number | null
  latency_ms: number | null
  checked_at: string | null
}

export type RaisedAlarm = {
  key: string
  label: string
  since: string
}

export type ProjectCard = {
  game_id: string
  display_name: string
  health_url: string
  ready_url: string
  health: ProbeSnapshot
  ready: ProbeSnapshot
  metrics: {
    count_24h: number
    totals_24h: Record<string, number>
    /** Levels at the last reading, such as players online. Never summed over the day. */
    gauges: Record<string, number>
  }
  /** Mailed and not yet cleared, oldest first. */
  alarms: RaisedAlarm[]
}

export type HealthHistoryRow = {
  endpoint: 'health' | 'ready'
  status: HealthStatus
  http_code: number
  latency_ms: number
  checked_at: string
  error: string | null
}

export type MetricRow = {
  name: string
  value: number
  tags: Record<string, string>
  recorded_at: string
}

export type UsageProvider = {
  id: string
  label: string
  tokens_in: number
  tokens_out: number
  cost_micros: number
}

export type UsageDay = {
  date: string
  tokens_in: number
  tokens_out: number
  cost_micros: number
  providers: UsageProvider[]
}

export type ProjectUsage = {
  window_days: number
  last_24h: {
    tokens_in: number
    tokens_out: number
    cost_micros: number
    providers: UsageProvider[]
  }
  days: UsageDay[]
}

export type ProjectDetail = {
  project: ProjectCard
  health_history: HealthHistoryRow[]
  recent_metrics: MetricRow[]
  usage?: ProjectUsage
}

export function isUsageMetric(name: string): boolean {
  return (
    name.startsWith('ai.tokens.') ||
    name.startsWith('ai.cost.micros') ||
    name.startsWith('chat.denied.') ||
    name.startsWith('abuse.')
  )
}

export type OverviewResponse = {
  projects: ProjectCard[]
  /** Newest probe across every project, or null when nothing has been probed yet. */
  last_probe_at: string | null
  /** True when that probe is older than the window below, which means nobody is watching. */
  stale: boolean
  stale_after_minutes: number
}

export type LogLine = {
  at: string
  message: string
  level: string | null
  type: string | null
}

export type ProjectLogs = {
  /** False when the host has no API key wired, which is a setup step rather than a fault. */
  configured: boolean
  /** The host that wrote these lines, so a panel is never read as the wrong service's. */
  source: string | null
  lines: LogLine[]
  note: string | null
}

export type LogQueryArgs = {
  gameId: string
  level?: string
  text?: string
}

export type ServiceAction = 'rebuild' | 'stop' | 'start'

export type ServiceState = {
  /** The name the host knows it by, which need not match the game id. */
  name: string
  /** Switched off on purpose, as opposed to failing. */
  stopped: boolean
  /** A deploy is in flight, so acting again would only queue behind it. */
  busy: boolean
  /** The last deploy did not land, which means the previous build is what is running. */
  failed: boolean
  deploy_status: string
  deploy_at: string | null
  /** Subject line of the commit that produced the running build. */
  commit: string
  summary: string
}

export type ServiceStatus = {
  /** False when the host has no API key wired, which is a setup step rather than a fault. */
  configured: boolean
  /** False keeps the panel readable and the buttons out of reach. */
  enabled: boolean
  state: ServiceState | null
  note: string | null
}

export type SessionResponse = {
  authenticated: boolean
  /**
   * True when the token may read the board but not act on it. A client that gets this hides
   * the controls rather than offering buttons every click would refuse.
   */
  readonly: boolean
}

export function displayStatus(status: string | null | undefined): HealthStatus {
  if (
    status === 'ok' ||
    status === 'ready' ||
    status === 'not_ready' ||
    status === 'down' ||
    status === 'timeout' ||
    status === 'throttled' ||
    status === 'error'
  ) {
    return status
  }

  return 'unseen'
}

export function isHealthy(status: string | null | undefined): boolean {
  return status === 'ok' || status === 'ready'
}
