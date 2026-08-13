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

export type ProjectDetail = {
  project: ProjectCard
  health_history: HealthHistoryRow[]
  recent_metrics: MetricRow[]
}

export type OverviewResponse = {
  projects: ProjectCard[]
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
