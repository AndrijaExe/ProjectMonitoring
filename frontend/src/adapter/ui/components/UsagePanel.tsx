import type { ProjectCard } from '../../../model/monitoring'

type Props = {
  card: ProjectCard
}

const TOKEN_IN = 'ai.tokens.in'
const TOKEN_OUT = 'ai.tokens.out'
const COST_MICROS = 'ai.cost.micros'

const RELATED: { name: string; label: string }[] = [
  { name: 'chat.messages', label: 'chat replies' },
  { name: 'ai.fallback', label: 'fell back to another provider' },
  { name: 'ai.failed', label: 'provider failures' },
  { name: 'safety.blocked', label: 'blocked by moderation' },
  { name: 'safety.unavailable', label: 'moderation unavailable' },
]

/**
 * What the game's own counters say about paid AI work in the last 24 hours.
 *
 * This is not a live pull from the provider's billing page. That would mean keeping their
 * key here. The game already sees usage on every completion and publishes it; this panel
 * only reads that.
 */
export function UsagePanel({ card }: Props) {
  const totals = card.metrics.totals_24h
  const tokensIn = totals[TOKEN_IN]
  const tokensOut = totals[TOKEN_OUT]
  const micros = totals[COST_MICROS]
  const related = RELATED.flatMap((row) => {
    const value = totals[row.name]
    return value == null ? [] : [{ ...row, value }]
  })
  const hasTokens = tokensIn != null || tokensOut != null
  const hasAnything = hasTokens || micros != null || related.length > 0

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

function formatCount(value: number | undefined): string {
  return value == null ? '—' : value.toLocaleString('en-US')
}

function formatUsd(micros: number | undefined): string {
  if (micros == null) {
    return '—'
  }

  return `$${(micros / 1_000_000).toFixed(4)}`
}

