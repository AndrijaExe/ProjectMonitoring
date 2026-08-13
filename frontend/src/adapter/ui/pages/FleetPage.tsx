import { Link } from 'react-router-dom'
import { displayStatus } from '../../../model/monitoring'
import {
  useGetOverviewQuery,
  usePollAllMutation,
  useSendTestAlertMutation,
} from '../../api/monitoringApi'
import { LogPanel } from '../components/LogPanel'
import { StatusChip } from '../components/StatusChip'
import { Shell } from '../components/Shell'
import { formatCheckedAt } from '../formatTime'
import { useStatusUpdate } from '../useStatusUpdate'

export function FleetPage() {
  const { data, isLoading, isError, refetch } = useGetOverviewQuery(undefined, {
    pollingInterval: 30_000,
  })
  const [pollAll] = usePollAllMutation()
  const [sendTestAlert, alertState] = useSendTestAlertMutation()
  const status = useStatusUpdate(pollAll)

  return (
    <Shell
      kicker="Live fleet"
      title="What is up right now"
      lede="Health and ready probes for every registered game. Loop 9 is seeded; the next title is another project card, not a rewrite."
      action={
        <div className="hero-actions">
          <button
            className="ghost"
            type="button"
            disabled={alertState.isLoading}
            onClick={() => void sendTestAlert()}
          >
            {alertState.isLoading ? 'Sending…' : 'Test alert'}
          </button>
          <button
            type="button"
            disabled={status.busy}
            onClick={() => void status.update((data?.projects ?? []).map((p) => p.health_url))}
          >
            {status.label}
          </button>
        </div>
      }
    >
      {alertState.data != null ? (
        <p className={alertState.data.sent ? 'empty' : 'alert'}>{alertState.data.note}</p>
      ) : null}
      {alertState.isError ? <p className="alert">The API refused the test alert.</p> : null}
      {isLoading ? <p className="empty">Reading the board…</p> : null}
      {isError ? (
        <p className="alert">
          Could not load the fleet.{' '}
          <button className="ghost" type="button" onClick={() => void refetch()}>
            Retry
          </button>
        </p>
      ) : null}
      {data && data.projects.length === 0 ? (
        <p className="empty">No projects registered yet.</p>
      ) : null}
      {data ? (
        <ul className="fleet">
          {data.projects.map((project) => (
            <li key={project.game_id} data-status={displayStatus(project.health.status)}>
              <Link to={`/projects/${project.game_id}`}>
                <div className="row">
                  <strong>{project.display_name}</strong>
                  <span className="mono">{project.game_id}</span>
                </div>
                <div className="chips">
                  <StatusChip label="health" status={project.health.status} />
                  <StatusChip label="ready" status={project.ready.status} />
                  {project.metrics.gauges?.['players.online'] != null ? (
                    <span className="chip chip-unseen">
                      <span className="chip-label">players online</span>
                      {project.metrics.gauges['players.online']}
                    </span>
                  ) : null}
                  <span className="chip chip-unseen">
                    <span className="chip-label">readings 24h</span>
                    {project.metrics.count_24h}
                  </span>
                </div>
                <p className="meta">
                  health {formatCheckedAt(project.health.checked_at)}
                  {project.health.latency_ms != null ? ` · ${project.health.latency_ms}ms` : ''}
                  {' · '}
                  ready {formatCheckedAt(project.ready.checked_at)}
                </p>
              </Link>
            </li>
          ))}
        </ul>
      ) : null}

      <LogPanel title="Monitor logs" />
    </Shell>
  )
}
