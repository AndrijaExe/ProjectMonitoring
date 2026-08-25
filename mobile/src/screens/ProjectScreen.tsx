import { useCallback, useState } from 'react'
import {
  ActivityIndicator,
  Pressable,
  RefreshControl,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native'
import {
  useGetProjectLogsQuery,
  useGetProjectQuery,
  useGetServiceStatusQuery,
  usePollProjectMutation,
} from '@shared/api/monitoringApi'
import { isUsageMetric, type ProjectDetail } from '@shared/model/monitoring'
import { formatCheckedAt } from '@shared/ui/formatTime'
import { useStatusUpdate } from '@shared/ui/useStatusUpdate'
import { Bar, Card, Empty, Muted, Row, SectionTitle, StatusPill } from '../components/parts'
import {
  USAGE_FAIR_USE,
  USAGE_RELATED,
  formatCount,
  formatDay,
  formatUsd,
  hasDayActivity,
  usageColorFor,
} from '@shared/ui/usage'
import { colors, mono, space } from '../theme'
import type { ScreenProps } from '../navigation'

const TABS = ['health', 'usage', 'logs'] as const
type Tab = (typeof TABS)[number]

export function ProjectScreen({ route }: ScreenProps<'Project'>) {
  const { gameId } = route.params
  const [tab, setTab] = useState<Tab>('health')
  const project = useGetProjectQuery(gameId)
  const [pollProject] = usePollProjectMutation()
  const status = useStatusUpdate(useCallback(() => pollProject(gameId), [pollProject, gameId]))

  const card = project.data?.project

  const refresh = useCallback(async () => {
    if (card) {
      await status.update([card.health_url, card.ready_url])
    }
    await project.refetch()
  }, [card, status, project])

  if (project.isLoading) {
    return (
      <View style={[styles.fill, styles.centre]}>
        <ActivityIndicator color={colors.lime} />
        <Muted>Reading snapshots…</Muted>
      </View>
    )
  }

  if (project.isError || !project.data || !card) {
    return (
      <View style={[styles.fill, styles.centre]}>
        <Text style={styles.error}>This game id is not registered on that API.</Text>
      </View>
    )
  }

  return (
    <ScrollView
      style={styles.fill}
      contentContainerStyle={styles.page}
      refreshControl={
        <RefreshControl
          refreshing={status.busy}
          onRefresh={refresh}
          tintColor={colors.lime}
          colors={[colors.lime]}
          progressBackgroundColor={colors.ink2}
          title={status.label}
          titleColor={colors.mute}
        />
      }
    >
      <View style={styles.pills}>
        <StatusPill label="health" status={card.health.status} />
        <StatusPill label="ready" status={card.ready.status} />
      </View>
      <Text style={styles.age}>
        {formatCheckedAt(card.health.checked_at)}
        {card.health.latency_ms === null ? '' : ` · ${card.health.latency_ms} ms`}
        {status.busy ? ` · ${status.label}` : ''}
      </Text>

      {card.alarms.length === 0 ? null : (
        <View style={styles.alarms}>
          <Text style={styles.alarmsTitle}>Open alarms</Text>
          {card.alarms.map((alarm) => (
            <View key={alarm.key} style={styles.alarmRow}>
              <Text style={styles.alarmLabel}>{alarm.label}</Text>
              <Text style={styles.alarmSince}>since {formatCheckedAt(alarm.since)}</Text>
            </View>
          ))}
        </View>
      )}

      <View style={styles.tabs}>
        {TABS.map((id) => (
          <Pressable key={id} onPress={() => setTab(id)} style={styles.tab}>
            <Text style={[styles.tabLabel, tab === id && styles.tabLabelActive]}>{id}</Text>
            <View style={[styles.tabRule, tab === id && styles.tabRuleActive]} />
          </Pressable>
        ))}
      </View>

      {tab === 'health' ? <HealthTab detail={project.data} gameId={gameId} /> : null}
      {tab === 'usage' ? <UsageTab detail={project.data} /> : null}
      {tab === 'logs' ? <LogsTab gameId={gameId} /> : null}
    </ScrollView>
  )
}

function HealthTab({ detail, gameId }: { detail: ProjectDetail; gameId: string }) {
  const card = detail.project
  // Token spend has its own tab, so this lane keeps the rest of what players did.
  const counters = Object.entries(card.metrics.totals_24h).filter(([name]) => !isUsageMetric(name))
  const gauges = Object.entries(card.metrics.gauges ?? {}).filter(
    ([name]) => !name.startsWith('abuse.'),
  )
  // The newest gauge row rather than the newest row of any kind, so the time under the levels
  // is when the levels were read.
  const lastGaugeAt =
    detail.recent_metrics.find((metric) => metric.tags?.kind === 'gauge')?.recorded_at ?? null

  return (
    <View>
      <ServiceCard gameId={gameId} />

      <SectionTitle>Recent probes</SectionTitle>
      {detail.health_history.length === 0 ? (
        <Empty>No probes stored yet. Pull down to take one.</Empty>
      ) : (
        <Card>
          {detail.health_history.slice(0, 12).map((row) => (
            <View
              key={`${row.endpoint}-${row.checked_at}-${row.http_code}-${row.latency_ms}`}
              style={styles.probe}
            >
              <View style={styles.probeHead}>
                <StatusPill label={row.endpoint} status={row.status} />
                <Text style={styles.probeWhen}>{formatCheckedAt(row.checked_at)}</Text>
              </View>
              <Text style={styles.probeMeta}>
                {row.http_code === 0 ? 'no answer' : `HTTP ${row.http_code}`} · {row.latency_ms} ms
              </Text>
              {row.error === null ? null : <Text style={styles.probeError}>{row.error}</Text>}
            </View>
          ))}
        </Card>
      )}

      {gauges.length === 0 ? null : (
        <>
          <SectionTitle>Right now</SectionTitle>
          <Card>
            {gauges.map(([name, value]) => (
              <Row key={name} label={name} value={String(value)} />
            ))}
            <Text style={styles.footnote}>read {formatCheckedAt(lastGaugeAt)}</Text>
          </Card>
        </>
      )}

      <SectionTitle>Last 24 hours</SectionTitle>
      {counters.length === 0 ? (
        // Said outright, because an empty list reads as a broken pipe. These counters move when
        // players do, and a probe is not a player.
        <Empty>
          Nothing counted yet. The game counts what players do — messages, logins, finished runs,
          errors — so this stays empty until somebody plays.
        </Empty>
      ) : (
        <Card>
          {counters.map(([name, total]) => (
            <Row key={name} label={name} value={String(total)} />
          ))}
        </Card>
      )}
    </View>
  )
}

/**
 * The run state, with no buttons on it.
 *
 * A read-only token could not stop or rebuild anything anyway, and knowing a service was
 * switched off on purpose is most of what the panel is for when a probe comes back red.
 */
function ServiceCard({ gameId }: { gameId: string }) {
  const service = useGetServiceStatusQuery(gameId)
  const state = service.data?.state

  if (service.isLoading || service.isError || !service.data) {
    return null
  }

  return (
    <>
      <SectionTitle>Service</SectionTitle>
      <Card>
        {state === null || state === undefined ? (
          <Muted>{service.data.note ?? 'The host is not wired up for this project.'}</Muted>
        ) : (
          <>
            <Row label="host name" value={state.name} />
            <Row label="state" value={state.summary} />
            <Row label="last deploy" value={formatCheckedAt(state.deploy_at)} />
            {state.commit === '' ? null : <Row label="commit" value={state.commit} />}
          </>
        )}
      </Card>
    </>
  )
}

function UsageTab({ detail }: { detail: ProjectDetail }) {
  const totals = detail.project.metrics.totals_24h
  const last24h = detail.usage?.last_24h
  const tokensIn = last24h?.tokens_in ?? totals['ai.tokens.in']
  const tokensOut = last24h?.tokens_out ?? totals['ai.tokens.out']
  const micros = last24h?.cost_micros ?? totals['ai.cost.micros']
  const providers = last24h?.providers ?? []
  const days = (detail.usage?.days ?? []).filter(hasDayActivity)
  const related = USAGE_RELATED.flatMap((row) => {
    const value = totals[row.name]
    return value == null ? [] : [{ ...row, value }]
  })
  const fairUse = USAGE_FAIR_USE.flatMap((row) => {
    const value = totals[row.name]
    return value == null ? [] : [{ ...row, value }]
  })
  const heaviest = detail.project.metrics.gauges?.['abuse.chats.heaviest']
  const hot = detail.project.metrics.gauges?.['abuse.players.hot']
  const useCost = days.some((day) => day.cost_micros > 0)
  const widest = Math.max(
    ...days.map((day) => (useCost ? day.cost_micros : day.tokens_in + day.tokens_out)),
    0,
  )
  const hasAnything =
    (tokensIn ?? 0) > 0 ||
    (tokensOut ?? 0) > 0 ||
    (micros ?? 0) > 0 ||
    related.length > 0 ||
    days.length > 0

  if (!hasAnything) {
    return (
      <Empty>
        Nothing billed yet. Token counts arrive with the next poll after the game has answered a
        chat — they come from the provider's own usage on each reply, not from a billing API.
      </Empty>
    )
  }

  return (
    <View>
      <SectionTitle>Last 24 hours</SectionTitle>
      <Card>
        <Row label="tokens in" value={formatCount(tokensIn)} />
        <Row label="tokens out" value={formatCount(tokensOut)} />
        <Row label="estimated spend" value={formatUsd(micros)} />
        <Text style={styles.footnote}>
          Estimated from the model rates the game already uses. A provider that did not report
          usage on a reply is not in these numbers.
        </Text>
      </Card>

      {providers.length === 0 ? null : (
        <>
          <SectionTitle>By provider</SectionTitle>
          <Card>
            {providers.map((provider) => (
              <View key={provider.id} style={styles.provider}>
                <View style={styles.providerHead}>
                  <View style={[styles.swatch, { backgroundColor: usageColorFor(provider.id) }]} />
                  <Text style={styles.providerName}>{provider.label}</Text>
                  <Text style={styles.providerSpend}>{formatUsd(provider.cost_micros)}</Text>
                </View>
                <Text style={styles.providerMeta}>
                  {formatCount(provider.tokens_in)} in · {formatCount(provider.tokens_out)} out
                </Text>
              </View>
            ))}
          </Card>
        </>
      )}

      {days.length === 0 || widest <= 0 ? null : (
        <>
          <SectionTitle>{useCost ? 'Spend by day' : 'Tokens by day'}</SectionTitle>
          <Card>
            {days.map((day) => {
              const value = useCost ? day.cost_micros : day.tokens_in + day.tokens_out
              return (
                <View key={day.date} style={styles.day}>
                  <View style={styles.dayHead}>
                    <Text style={styles.dayLabel}>{formatDay(day.date)}</Text>
                    <Text style={styles.dayValue}>
                      {useCost ? formatUsd(value) : formatCount(value)}
                    </Text>
                  </View>
                  <Bar share={value / widest} />
                </View>
              )
            })}
            <Text style={styles.footnote}>
              Growth between stored readings, in UTC days. The first reading of a series is a
              baseline, not a bill.
            </Text>
          </Card>
        </>
      )}

      {fairUse.length === 0 && !(heaviest ?? 0) && !(hot ?? 0) ? null : (
        <>
          <SectionTitle>Fair use</SectionTitle>
          <Card>
            {heaviest == null || heaviest === 0 ? null : (
              <Row label="heaviest player today" value={`${heaviest} chats`} />
            )}
            {hot == null || hot === 0 ? null : (
              <Row label="players over the watch line" value={String(hot)} />
            )}
            {fairUse.map((row) => (
              <Row key={row.name} label={row.label} value={String(row.value)} />
            ))}
            <Text style={styles.footnote}>
              These are hashed marks, so they say how hard someone is chatting today, never who.
            </Text>
          </Card>
        </>
      )}

      {related.length === 0 ? null : (
        <>
          <SectionTitle>Around the spend</SectionTitle>
          <Card>
            {related.map((row) => (
              <Row key={row.name} label={row.label} value={String(row.value)} />
            ))}
          </Card>
        </>
      )}
    </View>
  )
}

const LEVELS = ['', 'error', 'warning'] as const

function LogsTab({ gameId }: { gameId: string }) {
  const [level, setLevel] = useState<(typeof LEVELS)[number]>('')
  const logs = useGetProjectLogsQuery({ gameId, level: level === '' ? undefined : level })

  return (
    <View>
      <View style={styles.filters}>
        {LEVELS.map((option) => (
          <Pressable
            key={option || 'all'}
            onPress={() => setLevel(option)}
            style={[styles.filter, level === option && styles.filterActive]}
          >
            <Text style={[styles.filterLabel, level === option && styles.filterLabelActive]}>
              {option === '' ? 'all' : option}
            </Text>
          </Pressable>
        ))}
      </View>

      {logs.isLoading ? <ActivityIndicator color={colors.lime} /> : null}

      {logs.data?.configured === false ? (
        <Empty>{logs.data.note ?? 'The host has no API key wired, so there are no logs.'}</Empty>
      ) : null}

      {logs.data?.source == null ? null : (
        <Text style={styles.logSource}>from {logs.data.source}</Text>
      )}

      {(logs.data?.lines ?? []).length === 0 && logs.data?.configured !== false && !logs.isLoading ? (
        <Empty>Nothing logged in this window.</Empty>
      ) : null}

      {(logs.data?.lines ?? []).map((line, index) => (
        <View
          key={`${line.at}-${index}`}
          style={[
            styles.logLine,
            line.level === 'error' && styles.logError,
            line.level === 'warning' && styles.logWarning,
          ]}
        >
          <Text style={styles.logAt}>{formatCheckedAt(line.at)}</Text>
          <Text style={styles.logMessage}>{line.message}</Text>
        </View>
      ))}
    </View>
  )
}

const styles = StyleSheet.create({
  fill: {
    flex: 1,
    backgroundColor: colors.ink,
  },
  centre: {
    alignItems: 'center',
    justifyContent: 'center',
    gap: space.md,
    padding: space.xl,
  },
  page: {
    padding: space.lg,
    paddingBottom: 48,
  },
  error: {
    color: colors.alert,
    fontSize: 15,
    textAlign: 'center',
  },
  pills: {
    flexDirection: 'row',
    gap: space.sm,
  },
  age: {
    fontFamily: mono,
    fontSize: 11,
    letterSpacing: 1,
    textTransform: 'uppercase',
    color: colors.mute,
    marginTop: space.md,
  },
  alarms: {
    borderWidth: 1,
    borderLeftWidth: 3,
    borderColor: colors.alert,
    padding: space.md,
    marginTop: space.lg,
  },
  alarmsTitle: {
    fontFamily: mono,
    fontSize: 11,
    letterSpacing: 1.6,
    textTransform: 'uppercase',
    color: colors.alert,
    marginBottom: space.sm,
  },
  alarmRow: {
    paddingVertical: space.xs,
  },
  alarmLabel: {
    color: colors.paper,
    fontSize: 14,
  },
  alarmSince: {
    color: colors.mute,
    fontSize: 12,
  },
  tabs: {
    flexDirection: 'row',
    marginTop: space.xl,
    marginBottom: space.lg,
    borderBottomWidth: 1,
    borderBottomColor: colors.line,
  },
  tab: {
    marginRight: space.xl,
  },
  tabLabel: {
    fontFamily: mono,
    fontSize: 12,
    letterSpacing: 1.6,
    textTransform: 'uppercase',
    color: colors.mute,
    paddingBottom: space.sm,
  },
  tabLabelActive: {
    color: colors.lime,
  },
  tabRule: {
    height: 2,
    backgroundColor: 'transparent',
    marginBottom: -1,
  },
  tabRuleActive: {
    backgroundColor: colors.lime,
  },
  probe: {
    paddingVertical: space.sm,
    borderBottomWidth: 1,
    borderBottomColor: colors.line,
  },
  probeHead: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: space.sm,
  },
  probeWhen: {
    fontFamily: mono,
    fontSize: 11,
    color: colors.mute,
  },
  probeMeta: {
    color: colors.mute,
    fontSize: 12,
    marginTop: space.xs,
  },
  probeError: {
    color: colors.warn,
    fontSize: 12,
    marginTop: space.xs,
  },
  footnote: {
    color: colors.mute,
    fontSize: 12,
    lineHeight: 18,
    marginTop: space.md,
  },
  provider: {
    paddingVertical: space.sm,
    borderBottomWidth: 1,
    borderBottomColor: colors.line,
  },
  providerHead: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: space.sm,
  },
  swatch: {
    width: 8,
    height: 8,
    borderRadius: 2,
  },
  providerName: {
    color: colors.paper,
    fontSize: 15,
    flex: 1,
  },
  providerSpend: {
    fontFamily: mono,
    fontSize: 13,
    color: colors.paper,
  },
  providerMeta: {
    color: colors.mute,
    fontSize: 12,
    marginTop: space.xs,
    marginLeft: 16,
  },
  day: {
    paddingVertical: space.sm,
  },
  dayHead: {
    flexDirection: 'row',
    justifyContent: 'space-between',
  },
  dayLabel: {
    color: colors.paper,
    fontSize: 13,
  },
  dayValue: {
    fontFamily: mono,
    fontSize: 12,
    color: colors.mute,
  },
  filters: {
    flexDirection: 'row',
    gap: space.sm,
    marginBottom: space.lg,
  },
  filter: {
    borderWidth: 1,
    borderColor: colors.line,
    paddingHorizontal: space.md,
    paddingVertical: 6,
  },
  filterActive: {
    borderColor: colors.lime,
  },
  filterLabel: {
    fontFamily: mono,
    fontSize: 11,
    letterSpacing: 1,
    textTransform: 'uppercase',
    color: colors.mute,
  },
  filterLabelActive: {
    color: colors.lime,
  },
  logSource: {
    fontFamily: mono,
    fontSize: 11,
    color: colors.mute,
    marginBottom: space.sm,
  },
  logLine: {
    borderLeftWidth: 2,
    borderLeftColor: 'transparent',
    backgroundColor: colors.ink2,
    paddingHorizontal: space.sm,
    paddingVertical: space.sm,
    marginBottom: 2,
  },
  logError: {
    borderLeftColor: colors.alert,
  },
  logWarning: {
    borderLeftColor: colors.warn,
  },
  logAt: {
    fontFamily: mono,
    fontSize: 10,
    color: colors.mute,
  },
  logMessage: {
    fontFamily: mono,
    fontSize: 12,
    color: colors.paper,
    marginTop: 2,
  },
})
