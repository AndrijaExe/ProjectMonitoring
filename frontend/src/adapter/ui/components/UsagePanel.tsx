import type {
  ProjectCard,
  ProjectReport,
  ProjectUsage,
  ReportPeriod,
  UsageProvider,
} from '@shared/model/monitoring'
import {
  USAGE_FAIR_USE as FAIR_USE,
  USAGE_RELATED as RELATED,
  formatCount,
  formatDay,
  formatUsd,
  hasDayActivity,
  usageColorFor as colorFor,
} from '@shared/ui/usage'
import { axisLabel, bucketLabel, bucketSpan, currentLabel } from '@shared/ui/report'
import { BarChart, type BarPoint } from './BarChart'

type Props = {
  card: ProjectCard
  usage?: ProjectUsage
  /** When present, the chart and the table follow the page's period instead of the 14-day default. */
  report?: ProjectReport
  period: ReportPeriod
}

/** One row of the by-period table, whichever source it came from. */
type UsageRow = {
  key: string
  label: string
  axis: string
  title: string
  open: boolean
  tokens_in: number
  tokens_out: number
  cost_micros: number
  providers: UsageProvider[]
}

/**
 * What the game's own counters say about paid AI work.
 *
 * This is not a live pull from the provider's billing page. That would mean keeping their
 * key here. The game already sees usage on every completion and publishes it; this panel
 * only reads that, split by the host that was called.
 */
export function UsagePanel({ card, usage, report, period }: Props) {
  // With a report the headline follows the page's period; without one it is the 24-hour card.
  const current = report?.buckets[report.buckets.length - 1]
  const last24h = usage?.last_24h
  const totals = card.metrics.totals_24h
  const tokensIn = current?.usage.tokens_in ?? last24h?.tokens_in ?? totals['ai.tokens.in']
  const tokensOut = current?.usage.tokens_out ?? last24h?.tokens_out ?? totals['ai.tokens.out']
  const micros = current?.usage.cost_micros ?? last24h?.cost_micros ?? totals['ai.cost.micros']
  const providers = current?.usage.providers ?? last24h?.providers ?? []
  const headlineSpan = current ? currentLabel(period).toLowerCase() : 'last 24h'
  const days = usage?.days ?? []
  const rows: UsageRow[] = report
    ? report.buckets.map((bucket) => ({
        key: bucket.key,
        label: bucketLabel(bucket, period),
        axis: axisLabel(bucket, period),
        title: bucketSpan(bucket, period),
        open: !bucket.complete,
        ...bucket.usage,
      }))
    : days.map((day) => ({
        key: day.date,
        label: formatDay(day.date),
        axis: formatDay(day.date),
        title: formatDay(day.date),
        open: false,
        tokens_in: day.tokens_in,
        tokens_out: day.tokens_out,
        cost_micros: day.cost_micros,
        providers: day.providers,
      }))
  const grain = report ? period : 'day'
  const related = RELATED.flatMap((row) => {
    const value = totals[row.name]
    return value == null ? [] : [{ ...row, value }]
  })
  const fairUse = FAIR_USE.flatMap((row) => {
    const value = totals[row.name]
    return value == null ? [] : [{ ...row, value }]
  })
  const heaviest = card.metrics.gauges?.['abuse.chats.heaviest']
  const hot = card.metrics.gauges?.['abuse.players.hot']
  const hasFairUse = fairUse.length > 0 || (heaviest ?? 0) > 0 || (hot ?? 0) > 0
  const hasTokens = (tokensIn ?? 0) > 0 || (tokensOut ?? 0) > 0
  const hasSpend = (micros ?? 0) > 0
  const hasAnything =
    hasTokens || hasSpend || related.length > 0 || hasFairUse || days.some(hasDayActivity)

  return (
    <section className="usage">
      <h2>AI usage, {headlineSpan}</h2>
      {!hasAnything ? (
        <p className="empty">
          Nothing billed yet. Token counts arrive with the next poll after Loop 9 has answered
          a chat — they come from the provider&apos;s own usage on each reply, not from a
          separate billing API.
        </p>
      ) : (
        <>
          <ul className="totals usage-totals">
            <li>
              <span>tokens in</span>
              <span className="mono">{formatCount(tokensIn)}</span>
            </li>
            <li>
              <span>tokens out</span>
              <span className="mono">{formatCount(tokensOut)}</span>
            </li>
            <li>
              <span>estimated spend</span>
              <span className="mono">{formatUsd(micros)}</span>
            </li>
          </ul>
          <p className="meta">
            Estimated from the model rates the game already uses in its logs. A provider that
            did not report usage on a reply is not in these numbers.
          </p>
        </>
      )}

      {providers.length > 0 ? (
        <>
          <h3>By provider</h3>
          <table className="usage-table">
            <thead>
              <tr>
                <th>Provider</th>
                <th>In</th>
                <th>Out</th>
                <th>Spend</th>
              </tr>
            </thead>
            <tbody>
              {providers.map((provider) => (
                <tr key={provider.id}>
                  <td>
                    <span className="usage-swatch" style={{ background: colorFor(provider.id) }} />
                    {provider.label}
                  </td>
                  <td className="mono">{formatCount(provider.tokens_in)}</td>
                  <td className="mono">{formatCount(provider.tokens_out)}</td>
                  <td className="mono">{formatUsd(provider.cost_micros)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </>
      ) : null}

      <UsageChart rows={rows} grain={grain} />

      {rows.some(hasRowActivity) ? (
        <>
          <h3>By {grain}</h3>
          <table className="usage-table">
            <thead>
              <tr>
                <th>{grain === 'day' ? 'Day' : grain === 'week' ? 'Week' : 'Month'}</th>
                <th>In</th>
                <th>Out</th>
                <th>Spend</th>
              </tr>
            </thead>
            <tbody>
              {[...rows]
                .reverse()
                .filter(hasRowActivity)
                .map((row) => (
                  <tr key={row.key} className={row.open ? 'row-open' : ''}>
                    <td title={row.title}>
                      {row.open ? currentLabel(grain) : row.label}
                    </td>
                    <td className="mono">{formatCount(row.tokens_in)}</td>
                    <td className="mono">{formatCount(row.tokens_out)}</td>
                    <td className="mono">{formatUsd(row.cost_micros)}</td>
                  </tr>
                ))}
            </tbody>
          </table>
        </>
      ) : null}

      {hasFairUse ? (
        <>
          <h3>Fair use</h3>
          <p className="meta">
            A normal run is a handful of replies. These numbers are hashed marks, so they say
            how hard someone is chatting today, never who.
          </p>
          <ul className="totals">
            {heaviest != null && heaviest > 0 ? (
              <li>
                <span>heaviest player today</span>
                <span className="mono">{heaviest} chats</span>
              </li>
            ) : null}
            {hot != null && hot > 0 ? (
              <li>
                <span>players over the watch line</span>
                <span className="mono">{hot}</span>
              </li>
            ) : null}
            {fairUse.map((row) => (
              <li key={row.name}>
                <span>{row.label}</span>
                <span className="mono">{row.value}</span>
              </li>
            ))}
          </ul>
        </>
      ) : null}

      {related.length > 0 ? (
        <>
          <h3>Around the spend</h3>
          <ul className="totals">
            {related.map((row) => (
              <li key={row.name}>
                <span>{row.label}</span>
                <span className="mono">{row.value}</span>
              </li>
            ))}
          </ul>
        </>
      ) : null}
    </section>
  )
}

function hasRowActivity(row: UsageRow): boolean {
  return row.tokens_in > 0 || row.tokens_out > 0 || row.cost_micros > 0
}

function UsageChart({ rows, grain }: { rows: UsageRow[]; grain: ReportPeriod }) {
  if (rows.length === 0 || !rows.some(hasRowActivity)) {
    return null
  }

  const useCost = rows.some((row) => row.cost_micros > 0)
  const seen = new Map<string, UsageProvider>()
  for (const row of rows) {
    for (const provider of row.providers) {
      if (!seen.has(provider.id)) {
        seen.set(provider.id, provider)
      }
    }
  }
  const providers = [...seen.values()]

  const points: BarPoint[] = rows.map((row) => ({
    key: row.key,
    label: row.axis,
    title: row.title,
    open: row.open,
    parts: providers.map((provider) => {
      const match = row.providers.find((item) => item.id === provider.id)
      return {
        id: provider.id,
        label: provider.label,
        color: colorFor(provider.id),
        value: match ? (useCost ? match.cost_micros : match.tokens_in + match.tokens_out) : 0,
      }
    }),
  }))

  return (
    <>
      <h3>{useCost ? 'Estimated spend' : 'Tokens'} by {grain}</h3>
      <p className="meta">
        Last {rows.length} UTC {grain}s, stacked by provider. Growth between stored readings —
        the first reading of a series is a baseline, not a bill.
      </p>
      <BarChart
        title={`${useCost ? 'Estimated spend' : 'Tokens'} by ${grain}`}
        points={points}
        format={useCost ? formatUsd : formatCount}
      />
    </>
  )
}
