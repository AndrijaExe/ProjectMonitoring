import { useMemo, useState } from 'react'
import type { ProjectCard, ProjectReport, ReportBucket, ReportPeriod } from '@shared/model/monitoring'
import { formatCheckedAt } from '@shared/ui/formatTime'
import {
  COUNTER_GROUPS,
  HEADLINE_COUNTERS,
  axisLabel,
  bucketLabel,
  bucketSpan,
  changeBetween,
  comparisonLabel,
  currentLabel,
  elapsedShare,
  formatChange,
  groupFor,
  isProviderCopy,
  labelFor,
  previousLabel,
  seriesIn,
  sumOf,
  tableColumns,
} from '@shared/ui/report'
import { USAGE_COLORS, formatCount, formatUsd } from '@shared/ui/usage'
import { BarChart, type BarPoint } from './BarChart'

type Props = {
  card: ProjectCard
  report: ProjectReport | undefined
  period: ReportPeriod
  loading: boolean
  lastGaugeAt: string | null
}

type SortKey = { column: 'name' } | { column: 'bucket'; key: string }

const ENDING_COLORS = [
  '#c6f54a',
  '#6ec8ff',
  '#f0c14a',
  '#d4a5ff',
  '#ff9f7a',
  '#7fd6c2',
  '#e0e0e0',
]

/**
 * What the game did, cut into days, weeks or months.
 *
 * The 24-hour card answers "is anything happening"; this panel answers "is it more or less
 * than before", which needs equal windows. Every number here comes from one report, so the
 * tiles, the chart and the table are always the same cut.
 */
export function OverviewPanel({ card, report, period, loading, lastGaugeAt }: Props) {
  const buckets = report?.buckets ?? []
  const current = buckets[buckets.length - 1]
  const previous = buckets[buckets.length - 2]
  const share = elapsedShare(current)
  const [series, setSeries] = useState<string>('runs')
  const [sort, setSort] = useState<SortKey>({ column: 'bucket', key: '' })

  const gauges = Object.entries(card.metrics.gauges ?? {}).filter(
    ([name]) => !name.startsWith('abuse.'),
  )

  const headline = HEADLINE_COUNTERS.find((row) => row.key === series) ?? HEADLINE_COUNTERS[1]
  const chartPoints: BarPoint[] = buckets.map((bucket) => ({
    key: bucket.key,
    label: axisLabel(bucket, period),
    title: bucketSpan(bucket, period),
    open: !bucket.complete,
    parts: [
      {
        id: headline.key,
        label: headline.label,
        color: USAGE_COLORS[0],
        value: sumOf(bucket, headline.names),
      },
    ],
  }))

  const endings = useMemo(() => endingRows(current), [current])

  const columns = buckets.slice(-tableColumns(period))
  const names = seriesIn(columns).filter((name) => !isProviderCopy(name))
  const rows = useMemo(() => {
    const list = names.map((name) => ({
      name,
      group: groupFor(name),
      values: columns.map((bucket) => bucket.totals[name] ?? 0),
    }))
    const sortColumn = sort.column === 'bucket' ? Math.max(0, columns.findIndex((b) => b.key === sort.key)) : -1
    const pick = sort.column === 'bucket' && sortColumn < 0 ? columns.length - 1 : sortColumn
    list.sort((a, b) => {
      if (sort.column === 'name') {
        return labelFor(a.name).localeCompare(labelFor(b.name))
      }
      return (b.values[pick] ?? 0) - (a.values[pick] ?? 0) || a.name.localeCompare(b.name)
    })
    return list
  }, [names, columns, sort])

  const sortedColumnKey =
    sort.column === 'bucket' ? (columns.find((b) => b.key === sort.key) ?? columns[columns.length - 1])?.key : null

  return (
    <section className="overview">
      {gauges.length > 0 ? (
        <div className="now-strip">
          {gauges.map(([name, value]) => (
            <span key={name} className="now-item">
              <span className="now-value mono">{value}</span>
              <span className="now-label">{labelFor(name)}</span>
            </span>
          ))}
          <span className="now-read meta">read {formatCheckedAt(lastGaugeAt)}</span>
        </div>
      ) : null}

      <div className="tiles">
        {HEADLINE_COUNTERS.map((tile) => {
          const now = sumOf(current, tile.names)
          const before = sumOf(previous, tile.names)
          const change = changeBetween(now, before, share)
          const better = change == null ? null : tile.goodWhen === 'up' ? change > 0 : change < 0
          const tone = change == null || change === 0 ? '' : better ? ' tile-good' : ' tile-bad'
          return (
            <button
              key={tile.key}
              type="button"
              className={`tile${series === tile.key ? ' tile-active' : ''}${tone}`}
              onClick={() => setSeries(tile.key)}
              aria-pressed={series === tile.key}
            >
              <span className="tile-label">{tile.label}</span>
              <span className="tile-value">
                {loading && !report ? '…' : tile.money ? formatUsd(now) : formatCount(now)}
              </span>
              <span className="tile-delta">
                {change == null
                  ? `${previousLabel(period)}: ${tile.money ? formatUsd(before) : formatCount(before)}`
                  : `${formatChange(change)} ${comparisonLabel(period, share)}`}
              </span>
            </button>
          )
        })}
      </div>
      <p className="meta tiles-caption">
        {currentLabel(period)}
        {current ? ` (${bucketSpan(current, period)}, still filling)` : ''}. Pick a tile to chart it.
      </p>

      <div className="split overview-split">
        <article>
          <h2>{headline.label} by {period}</h2>
          <BarChart
            title={`${headline.label} by ${period}`}
            points={chartPoints}
            format={headline.money ? formatUsd : formatCount}
          />
          <p className="meta">
            {buckets.length} {period}s, UTC. The hatched bar is the {period} in progress.
          </p>
        </article>
        <article>
          <h2>Endings, {currentLabel(period).toLowerCase()}</h2>
          {endings.total === 0 ? (
            <p className="empty">No run has finished {currentLabel(period).toLowerCase()}.</p>
          ) : (
            <ul className="endings">
              {endings.rows.map((row, index) => (
                <li key={row.name}>
                  <span className="ending-name">{labelFor(row.name)}</span>
                  <span className="ending-bar">
                    <span
                      className="ending-fill"
                      style={{
                        width: `${Math.max(2, (row.value / endings.total) * 100)}%`,
                        background: ENDING_COLORS[index % ENDING_COLORS.length],
                      }}
                    />
                  </span>
                  <span className="ending-count mono">
                    {row.value} · {Math.round((row.value / endings.total) * 100)}%
                  </span>
                </li>
              ))}
            </ul>
          )}
          {endings.total > 0 ? <p className="meta">{endings.total} runs finished.</p> : null}
        </article>
      </div>

      <article className="counters">
        <h2>Every counter by {period}</h2>
        {columns.length === 0 ? (
          <p className="empty">No readings yet.</p>
        ) : (
          <div className="table-scroll">
            <table className="counter-table">
              <thead>
                <tr>
                  <th>
                    <button
                      type="button"
                      className={`sort${sort.column === 'name' ? ' sort-active' : ''}`}
                      onClick={() => setSort({ column: 'name' })}
                    >
                      Counter
                    </button>
                  </th>
                  {columns.map((bucket) => (
                    <th key={bucket.key} className={bucket.complete ? '' : 'col-open'}>
                      <button
                        type="button"
                        className={`sort${sortedColumnKey === bucket.key ? ' sort-active' : ''}`}
                        title={bucketSpan(bucket, period)}
                        onClick={() => setSort({ column: 'bucket', key: bucket.key })}
                      >
                        {period === 'week' ? (
                          <>
                            {axisLabel(bucket, period)}
                            <small>{bucketLabel(bucket, period).split(' · ')[1]}</small>
                          </>
                        ) : (
                          bucketLabel(bucket, period)
                        )}
                        {bucket.complete ? '' : ' *'}
                      </button>
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {COUNTER_GROUPS.map((group) => {
                  const inGroup = rows.filter((row) => row.group === group.id)
                  if (inGroup.length === 0) {
                    return null
                  }
                  return [
                    <tr key={`${group.id}-head`} className="group-row">
                      <th colSpan={columns.length + 1}>{group.label}</th>
                    </tr>,
                    ...inGroup.map((row) => (
                      <tr key={row.name}>
                        <td title={row.name}>{labelFor(row.name)}</td>
                        {row.values.map((value, index) => (
                          <td
                            key={columns[index].key}
                            className={`mono num${value === 0 ? ' zero' : ''}${columns[index].complete ? '' : ' col-open'}`}
                          >
                            {row.name === 'ai.cost.micros' || row.name.startsWith('ai.cost.micros.')
                              ? formatUsd(value)
                              : formatCount(value)}
                          </td>
                        ))}
                      </tr>
                    )),
                  ]
                })}
              </tbody>
            </table>
          </div>
        )}
        <p className="meta">
          Click a column to rank by it, or the first column for A–Z. * marks the {period} still filling.
          Counters are growth inside each {period}; the first {period} a series appears understates,
          because its earlier readings are unknown.
        </p>
      </article>
    </section>
  )
}

function endingRows(bucket: ReportBucket | undefined): {
  total: number
  rows: { name: string; value: number }[]
} {
  if (!bucket) {
    return { total: 0, rows: [] }
  }

  const rows = Object.entries(bucket.totals)
    .filter(([name, value]) => name.startsWith('run.ended.') && value > 0)
    .map(([name, value]) => ({ name, value }))
    .sort((a, b) => b.value - a.value)

  return { total: rows.reduce((sum, row) => sum + row.value, 0), rows }
}
