import { displayStatus, type HealthStatus } from '@shared/model/monitoring'

type StatusChipProps = {
  label?: string
  status: string | null | undefined
}

const LABELS: Record<HealthStatus, string> = {
  ok: 'ok',
  ready: 'ready',
  not_ready: 'not ready',
  down: 'down',
  timeout: 'timeout',
  throttled: 'throttled',
  error: 'error',
  unseen: 'unseen',
}

export function StatusChip({ label, status }: StatusChipProps) {
  const resolved = displayStatus(status)

  return (
    <span className={`chip chip-${resolved}`}>
      {label ? <span className="chip-label">{label}</span> : null}
      {LABELS[resolved]}
    </span>
  )
}
