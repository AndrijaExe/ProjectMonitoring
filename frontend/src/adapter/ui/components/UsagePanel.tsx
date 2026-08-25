import type { ProjectCard, ProjectUsage, UsageDay } from '@shared/model/monitoring'
import {
  USAGE_FAIR_USE as FAIR_USE,
  USAGE_RELATED as RELATED,
  formatCount,
  formatDay,
  formatUsd,
  hasDayActivity,
  uniqueProviders,
  usageColorFor as colorFor,
  valueFor,
} from '@shared/ui/usage'

type Props = {
  card: ProjectCard
  usage?: ProjectUsage
}

/**
 * What the game's own counters say about paid AI work.
 *
 * This is not a live pull from the provider's billing page. That would mean keeping their
 * key here. The game already sees usage on every completion and publishes it; this panel
 * only reads that, split by the host that was called.
 */
export function UsagePanel({ card, usage }: Props) {
  const last24h = usage?.last_24h
  const totals = card.metrics.totals_24h
  const tokensIn = last24h?.tokens_in ?? totals['ai.tokens.in']
  const tokensOut = last24h?.tokens_out ?? totals['ai.tokens.out']
  const micros = last24h?.cost_micros ?? totals['ai.cost.micros']
  const providers = last24h?.providers ?? []
  const days = usage?.days ?? []
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
      <h2>AI usage, last 24h</h2>
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

      <DailyChart days={days} />

      {days.some(hasDayActivity) ? (
        <>
          <h3>By day</h3>
          <table className="usage-table">
            <thead>
              <tr>
                <th>Day</th>
                <th>In</th>
                <th>Out</th>
                <th>Spend</th>
              </tr>
            </thead>
            <tbody>
              {days.filter(hasDayActivity).map((day) => (
                <tr key={day.date}>
                  <td>{formatDay(day.date)}</td>
                  <td className="mono">{formatCount(day.tokens_in)}</td>
                  <td className="mono">{formatCount(day.tokens_out)}</td>
                  <td className="mono">{formatUsd(day.cost_micros)}</td>
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

function DailyChart({ days }: { days: UsageDay[] }) {
  if (days.length === 0 || !days.some(hasDayActivity)) {
    return null
  }

  const useCost = days.some((day) => day.cost_micros > 0)
  const providers = uniqueProviders(days)
  const stacked = days.map((day) =>
    providers.map((provider) => valueFor(day, provider, useCost)),
  )
  const max = Math.max(
    ...stacked.map((parts) => parts.reduce((sum, part) => sum + part, 0)),
    0,
  )

  if (max <= 0) {
    return null
  }

  const width = 640
  const height = 176
  const left = 52
  const right = 12
  const top = 12
  const bottom = 28
  const innerWidth = width - left - right
  const innerHeight = height - top - bottom
  const gap = 4
  const barWidth = Math.max(8, innerWidth / days.length - gap)

  return (
    <>
      <h3>{useCost ? 'Estimated spend by day' : 'Tokens by day'}</h3>
      <p className="meta">
        Last {days.length} UTC days. Growth between stored readings — the first reading of a
        series is a baseline, not a bill.
      </p>
      <svg className="usage-chart" viewBox={`0 0 ${width} ${height}`} role="img">
        <title>{useCost ? 'Estimated spend by day' : 'Tokens by day'}</title>
        {[0, 0.5, 1].map((tick) => {
          const y = top + innerHeight * (1 - tick)
          const value = max * tick
          return (
            <g key={tick}>
              <line
                x1={left}
                x2={width - right}
                y1={y}
                y2={y}
                stroke="rgba(198, 245, 74, 0.12)"
              />
              <text x={left - 8} y={y + 4} textAnchor="end" className="usage-axis">
                {useCost ? formatUsd(value) : formatCount(value)}
              </text>
            </g>
          )
        })}
        {days.map((day, index) => {
          const x = left + (innerWidth / days.length) * index + gap / 2
          let y = top + innerHeight
          const parts = stacked[index]
          const labelEvery = days.length > 10 ? 2 : 1

          return (
            <g key={day.date}>
              {parts.map((value, partIndex) => {
                const barHeight = (value / max) * innerHeight
                y -= barHeight
                if (barHeight <= 0) {
                  return null
                }

                const provider = providers[partIndex]
                return (
                  <rect
                    key={provider.id}
                    x={x}
                    y={y}
                    width={barWidth}
                    height={barHeight}
                    fill={colorFor(provider.id)}
                  >
                    <title>
                      {`${formatDay(day.date)} · ${provider.label} · ${
                        useCost ? formatUsd(value) : formatCount(value)
                      }`}
                    </title>
                  </rect>
                )
              })}
              {index % labelEvery === 0 ? (
                <text
                  x={x + barWidth / 2}
                  y={height - 8}
                  textAnchor="middle"
                  className="usage-axis"
                >
                  {formatDay(day.date)}
                </text>
              ) : null}
            </g>
          )
        })}
      </svg>
    </>
  )
}

