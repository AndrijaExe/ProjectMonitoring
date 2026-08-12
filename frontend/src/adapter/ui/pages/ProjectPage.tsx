import { Link, useParams } from 'react-router-dom'
import { useGetProjectQuery, usePollProjectMutation } from '../../api/monitoringApi'
import { StatusChip } from '../components/StatusChip'
import { Shell } from '../components/Shell'
import { formatCheckedAt } from '../formatTime'

export function ProjectPage() {
  const { gameId = '' } = useParams()
  const { data, isLoading, isError } = useGetProjectQuery(gameId, {
    skip: gameId === '',
    pollingInterval: 30_000,
  })
  const [pollProject, pollState] = usePollProjectMutation()

  if (isLoading) {
    return (
      <Shell kicker="Project" title={gameId} lede="Loading probe history…">
        <p className="empty">Reading snapshots…</p>
      </Shell>
    )
  }

  if (isError || !data) {
    return (
      <Shell
        kicker={
          <Link className="crumb" to="/">
            Fleet
          </Link>
        }
        title="Missing project"
      >
        <p className="alert">This game id is not registered.</p>
      </Shell>
    )
  }

  const card = data.project

  return (
    <Shell
      kicker={
        <>
          <Link className="crumb" to="/">
            Fleet
          </Link>
          <span> / {card.game_id}</span>
        </>
      }
      title={card.display_name}
      lede={`Health ${card.health.status ?? 'unseen'}${card.health.latency_ms != null ? ` in ${card.health.latency_ms}ms` : ''}. Ready ${card.ready.status ?? 'unseen'}${card.ready.latency_ms != null ? ` in ${card.ready.latency_ms}ms` : ''}.`}
      action={
        <button
          type="button"
          disabled={pollState.isLoading}
          onClick={() => void pollProject(card.game_id)}
        >
          {pollState.isLoading ? 'Polling…' : 'Poll now'}
        </button>
      }
    >
      <div className="chips page-chips">
        <StatusChip label="health" status={card.health.status} />
        <StatusChip label="ready" status={card.ready.status} />
        <span className="chip chip-unseen">
          <span className="chip-label">ingest 24h</span>
          {card.metrics.count_24h}
        </span>
      </div>

      <section className="split">
        <article>
          <h2>Health history</h2>
          {data.health_history.length === 0 ? (
            <p className="empty">No probes stored yet. Poll once to fill this lane.</p>
          ) : (
            <table>
              <thead>
                <tr>
                  <th>When</th>
                  <th>Probe</th>
                  <th>Status</th>
                  <th>HTTP</th>
                  <th>ms</th>
                </tr>
              </thead>
              <tbody>
                {data.health_history.map((row) => (
                  <tr key={`${row.endpoint}-${row.checked_at}-${row.http_code}-${row.latency_ms}`}>
                    <td className="mono">{formatCheckedAt(row.checked_at)}</td>
                    <td>{row.endpoint}</td>
                    <td>
                      <StatusChip status={row.status} />
                    </td>
                    <td className="mono">{row.http_code}</td>
                    <td className="mono">{row.latency_ms}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </article>
        <article>
          <h2>Recent ingest</h2>
          {Object.keys(card.metrics.totals_24h).length > 0 ? (
            <ul className="totals">
              {Object.entries(card.metrics.totals_24h).map(([name, total]) => (
                <li key={name}>
                  <span>{name}</span>
                  <span className="mono">{total}</span>
                </li>
              ))}
            </ul>
          ) : null}
          {data.recent_metrics.length === 0 ? (
            <p className="empty">
              No metrics yet. POST /api/v1/projects/{card.game_id}/metrics with X-Ingest-Token.
            </p>
          ) : (
            <table>
              <thead>
                <tr>
                  <th>When</th>
                  <th>Name</th>
                  <th>Value</th>
                </tr>
              </thead>
              <tbody>
                {data.recent_metrics.map((metric) => (
                  <tr key={`${metric.name}-${metric.recorded_at}-${metric.value}`}>
                    <td className="mono">{formatCheckedAt(metric.recorded_at)}</td>
                    <td>{metric.name}</td>
                    <td className="mono">{metric.value}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </article>
      </section>
    </Shell>
  )
}
