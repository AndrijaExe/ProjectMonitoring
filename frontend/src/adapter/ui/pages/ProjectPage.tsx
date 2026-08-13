import { Link, useParams } from 'react-router-dom'
import {
  useClearHistoryMutation,
  useGetProjectQuery,
  usePollProjectMutation,
} from '../../api/monitoringApi'
import { SeeMore } from '../components/SeeMore'
import { useSeeMore } from '../useSeeMore'
import { LogPanel } from '../components/LogPanel'
import { StatusChip } from '../components/StatusChip'
import { Shell } from '../components/Shell'
import { formatCheckedAt } from '../formatTime'
import { useStatusUpdate } from '../useStatusUpdate'

export function ProjectPage() {
  const { gameId = '' } = useParams()
  const { data, isLoading, isError } = useGetProjectQuery(gameId, {
    skip: gameId === '',
    pollingInterval: 30_000,
  })
  const [pollProject] = usePollProjectMutation()
  const status = useStatusUpdate(() => pollProject(gameId))
  const [clearHistory, clearState] = useClearHistoryMutation()
  const history = useSeeMore(data?.health_history ?? [])
  const metrics = useSeeMore(data?.recent_metrics ?? [])

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
        <div className="hero-actions">
          <button
            className="ghost"
            type="button"
            disabled={clearState.isLoading || data.health_history.length === 0}
            onClick={() => {
              // A destructive action deserves a question, and the count makes the
              // consequence concrete rather than abstract.
              if (window.confirm(`Delete ${data.health_history.length} stored probe rows for ${card.display_name}?`)) {
                void clearHistory(card.game_id)
              }
            }}
          >
            {clearState.isLoading ? 'Clearing…' : 'Clear history'}
          </button>
          <button
            type="button"
            disabled={status.busy}
            onClick={() => void status.update([card.health_url])}
          >
            {status.label}
          </button>
        </div>
      }
    >
      <div className="chips page-chips">
        <StatusChip label="health" status={card.health.status} />
        <StatusChip label="ready" status={card.ready.status} />
        <span className="chip chip-unseen">
          <span className="chip-label">readings 24h</span>
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
                  <th>Note</th>
                </tr>
              </thead>
              <tbody>
                {history.visible.map((row) => (
                  <tr key={`${row.endpoint}-${row.checked_at}-${row.http_code}-${row.latency_ms}`}>
                    <td className="mono">{formatCheckedAt(row.checked_at)}</td>
                    <td>{row.endpoint}</td>
                    <td>
                      <StatusChip status={row.status} />
                    </td>
                    <td className="mono">{row.http_code === 0 ? '—' : row.http_code}</td>
                    <td className="mono">{row.latency_ms}</td>
                    <td className="note">{row.error ?? ''}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
          <SeeMore
            hidden={history.hidden}
            expanded={history.expanded}
            onMore={history.showMore}
            onLess={history.showLess}
          />
        </article>
        <article>
          <h2>Counters, last 24h</h2>
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
              No readings yet. Counters are read from the game each time a poll finds it up.
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
                {metrics.visible.map((metric) => (
                  <tr key={`${metric.name}-${metric.recorded_at}-${metric.value}`}>
                    <td className="mono">{formatCheckedAt(metric.recorded_at)}</td>
                    <td>{metric.name}</td>
                    <td className="mono">{metric.value}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
          <SeeMore
            hidden={metrics.hidden}
            expanded={metrics.expanded}
            onMore={metrics.showMore}
            onLess={metrics.showLess}
          />
        </article>
      </section>

      <LogPanel title="Logs" gameId={card.game_id} />
    </Shell>
  )
}
