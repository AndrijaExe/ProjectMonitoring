import { useCallback } from 'react'
import {
  ActivityIndicator,
  Pressable,
  RefreshControl,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native'
import { displayStatus, type ProjectCard } from '@shared/model/monitoring'
import { useGetOverviewQuery, usePollAllMutation } from '@shared/api/monitoringApi'
import { formatCheckedAt } from '@shared/ui/formatTime'
import { useStatusUpdate } from '@shared/ui/useStatusUpdate'
import { Empty, Kicker, Muted, StatusPill } from '../components/parts'
import { colors, mono, space, statusColor } from '../theme'
import { usePushAlerts } from '../usePushAlerts'
import type { ScreenProps } from '../navigation'

export function FleetScreen({ navigation }: ScreenProps<'Fleet'>) {
  const overview = useGetOverviewQuery()
  const [pollAll] = usePollAllMutation()
  // This screen is the one that is always mounted while signed in, which makes it the right
  // place to register for alerts exactly once and the only place with room to say so.
  const pushProblem = usePushAlerts()
  // Not unwrapped: a refused poll is reported by the board going stale, and a rejection here
  // would escape through the refresh gesture as an unhandled error.
  const status = useStatusUpdate(useCallback(() => pollAll(), [pollAll]))

  const projects = overview.data?.projects ?? []

  /**
   * Pull to refresh does what the console's button does: wake the targets from this device
   * first, then ask the API to probe them. A probe sent to a sleeping free instance comes back
   * rate limited and would put "throttled" on the board instead of an answer.
   */
  const refresh = useCallback(async () => {
    const urls = projects.flatMap((project) => [project.health_url, project.ready_url])
    await status.update(urls)
    await overview.refetch()
  }, [projects, status, overview])

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
      <Kicker>{projects.length === 1 ? '1 project' : `${projects.length} projects`}</Kicker>
      <Text style={styles.title}>Fleet</Text>

      {overview.data?.stale === true ? (
        <View style={styles.warning}>
          <Text style={styles.warningText}>
            Nothing has been probed for over {overview.data.stale_after_minutes} minutes, so the
            schedule has stopped rather than a run being slow. Pull to probe now.
          </Text>
        </View>
      ) : null}

      <Text style={styles.age}>
        Last probe {formatCheckedAt(overview.data?.last_probe_at)}
        {status.busy ? ` · ${status.label}` : ''}
      </Text>

      {/* Quiet rather than alarming: alerts being off is worth knowing, and is not an outage. */}
      {pushProblem === null ? null : (
        <Text style={styles.pushOff}>Alerts off · {pushProblem}</Text>
      )}

      {overview.isLoading ? (
        <View style={styles.loading}>
          <ActivityIndicator color={colors.lime} />
          <Muted>Waking the API can take the better part of a minute.</Muted>
        </View>
      ) : null}

      {overview.isError ? (
        <Empty>The board could not be read. Pull down to try again.</Empty>
      ) : null}

      {projects.map((project) => (
        <ProjectRow
          key={project.game_id}
          project={project}
          onPress={() =>
            navigation.navigate('Project', {
              gameId: project.game_id,
              displayName: project.display_name,
            })
          }
        />
      ))}

      {!overview.isLoading && !overview.isError && projects.length === 0 ? (
        <Empty>No projects are configured on this API.</Empty>
      ) : null}
    </ScrollView>
  )
}

function ProjectRow({ project, onPress }: { project: ProjectCard; onPress: () => void }) {
  // The health probe decides the stripe. It is the one that says whether anybody outside can
  // reach the thing at all.
  const stripe = statusColor(displayStatus(project.health.status))
  const alarms = project.alarms.length

  return (
    <Pressable
      onPress={onPress}
      style={({ pressed }) => [
        styles.card,
        { borderLeftColor: stripe },
        pressed && styles.cardPressed,
      ]}
    >
      <View style={styles.cardHead}>
        <Text style={styles.name}>{project.display_name}</Text>
        <Text style={styles.gameId}>{project.game_id}</Text>
      </View>

      <View style={styles.pills}>
        <StatusPill label="health" status={project.health.status} />
        <StatusPill label="ready" status={project.ready.status} />
      </View>

      <Text style={styles.meta}>
        {formatCheckedAt(project.health.checked_at)}
        {project.health.latency_ms === null ? '' : ` · ${project.health.latency_ms} ms`}
        {` · ${project.metrics.count_24h} metrics in 24h`}
      </Text>

      {alarms === 0 ? null : (
        <Text style={styles.alarms}>
          {alarms === 1 ? '1 alarm raised' : `${alarms} alarms raised`}: {project.alarms
            .map((alarm) => alarm.label)
            .join(', ')}
        </Text>
      )}
    </Pressable>
  )
}

const styles = StyleSheet.create({
  fill: {
    flex: 1,
    backgroundColor: colors.ink,
  },
  page: {
    padding: space.lg,
    paddingBottom: 48,
  },
  title: {
    color: colors.paper,
    fontSize: 34,
    fontWeight: '500',
    letterSpacing: -1,
    marginTop: space.xs,
    marginBottom: space.md,
  },
  age: {
    fontFamily: mono,
    fontSize: 11,
    letterSpacing: 1,
    textTransform: 'uppercase',
    color: colors.mute,
    marginBottom: space.lg,
  },
  pushOff: {
    color: colors.mute,
    fontSize: 12,
    lineHeight: 18,
    marginTop: -space.md,
    marginBottom: space.lg,
  },
  warning: {
    borderWidth: 1,
    borderLeftWidth: 3,
    borderColor: colors.warn,
    padding: space.md,
    marginBottom: space.md,
  },
  warningText: {
    color: colors.warn,
    fontSize: 13,
    lineHeight: 19,
  },
  loading: {
    gap: space.md,
    paddingVertical: space.xl,
    alignItems: 'center',
  },
  card: {
    borderWidth: 1,
    borderLeftWidth: 3,
    borderColor: colors.line,
    backgroundColor: colors.ink2,
    padding: space.lg,
    marginBottom: space.md,
  },
  cardPressed: {
    backgroundColor: '#101d16',
  },
  cardHead: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'baseline',
    gap: space.sm,
  },
  name: {
    color: colors.paper,
    fontSize: 20,
    fontWeight: '500',
    flexShrink: 1,
  },
  gameId: {
    fontFamily: mono,
    fontSize: 11,
    color: colors.mute,
  },
  pills: {
    flexDirection: 'row',
    gap: space.sm,
    marginTop: space.md,
  },
  meta: {
    color: colors.mute,
    fontSize: 13,
    marginTop: space.md,
  },
  alarms: {
    color: colors.alert,
    fontSize: 13,
    marginTop: space.sm,
  },
})
