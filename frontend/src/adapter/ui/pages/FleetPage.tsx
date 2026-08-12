import { Link } from 'react-router-dom'
import { displayStatus } from '../../../model/monitoring'
import { useGetOverviewQuery, usePollAllMutation } from '../../api/monitoringApi'
import { StatusChip } from '../components/StatusChip'
import { Shell } from '../components/Shell'
import { formatCheckedAt } from '../formatTime'

export function FleetPage() {
  const { data, isLoading, isError, refetch } = useGetOverviewQuery(undefined, {
    pollingInterval: 30_000,
  })
  const [pollAll, pollState] = usePollAllMutation()

  return (
    <Shell
      kicker="Live fleet"
      title="What is up right now"
      lede="Health and ready probes for every registered game. Loop 9 is seeded; the next title is another project card, not a rewrite."
      action={
        <button type="button" disabled={pollState.isLoading} onClick={() => void pollAll()}>
          {pollState.isLoading ? 'Polling…' : 'Poll all now'}
        </button>
      }
    >
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
                  <span className="chip chip-unseen">
                    <span className="chip-label">ingest 24h</span>
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
    </Shell>
  )
}
