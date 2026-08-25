import type { ReactNode } from 'react'
import { StyleSheet, Text, View } from 'react-native'
import { displayStatus } from '@shared/model/monitoring'
import { colors, mono, space, statusColor } from '../theme'

/** The small uppercase labels the console uses instead of headings. */
export function Kicker({ children }: { children: ReactNode }) {
  return <Text style={styles.kicker}>{children}</Text>
}

export function SectionTitle({ children }: { children: ReactNode }) {
  return <Text style={styles.sectionTitle}>{children}</Text>
}

export function Muted({ children }: { children: ReactNode }) {
  return <Text style={styles.muted}>{children}</Text>
}

export function Mono({ children }: { children: ReactNode }) {
  return <Text style={styles.mono}>{children}</Text>
}

export function Card({ children }: { children: ReactNode }) {
  return <View style={styles.card}>{children}</View>
}

/** Label on the left, value on the right, which is most of what a monitor has to say. */
export function Row({ label, value }: { label: string; value: string }) {
  return (
    <View style={styles.row}>
      <Text style={styles.rowLabel}>{label}</Text>
      <Text style={styles.rowValue}>{value}</Text>
    </View>
  )
}

export function StatusPill({ label, status }: { label: string; status: string | null | undefined }) {
  const resolved = displayStatus(status)
  const tint = statusColor(resolved)

  return (
    <View style={[styles.pill, { borderColor: tint }]}>
      <Text style={styles.pillLabel}>{label}</Text>
      <Text style={[styles.pillValue, { color: tint }]}>{resolved.replace('_', ' ')}</Text>
    </View>
  )
}

/** A share of the widest value in a set, drawn as a bar because a phone has no room for axes. */
export function Bar({ share, tint = colors.lime }: { share: number; tint?: string }) {
  const width = `${Math.max(0, Math.min(1, share)) * 100}%` as const

  return (
    <View style={styles.barTrack}>
      <View style={[styles.barFill, { width, backgroundColor: tint }]} />
    </View>
  )
}

export function Empty({ children }: { children: ReactNode }) {
  return <Text style={styles.empty}>{children}</Text>
}

const styles = StyleSheet.create({
  kicker: {
    fontFamily: mono,
    fontSize: 11,
    letterSpacing: 2,
    textTransform: 'uppercase',
    color: colors.lime,
  },
  sectionTitle: {
    fontFamily: mono,
    fontSize: 11,
    letterSpacing: 1.6,
    textTransform: 'uppercase',
    color: colors.mute,
    marginBottom: space.sm,
  },
  muted: {
    color: colors.mute,
    fontSize: 14,
  },
  mono: {
    fontFamily: mono,
    fontSize: 12,
    color: colors.mute,
  },
  card: {
    borderWidth: 1,
    borderColor: colors.line,
    backgroundColor: colors.ink2,
    padding: space.lg,
    marginBottom: space.md,
  },
  row: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'flex-start',
    gap: space.md,
    paddingVertical: space.sm,
    borderBottomWidth: 1,
    borderBottomColor: colors.line,
  },
  rowLabel: {
    fontFamily: mono,
    fontSize: 11,
    letterSpacing: 1,
    textTransform: 'uppercase',
    color: colors.mute,
    flexShrink: 1,
  },
  rowValue: {
    color: colors.paper,
    fontSize: 14,
    textAlign: 'right',
    flexShrink: 1,
  },
  pill: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: space.sm,
    borderWidth: 1,
    paddingHorizontal: space.sm,
    paddingVertical: 3,
  },
  pillLabel: {
    fontFamily: mono,
    fontSize: 10,
    letterSpacing: 1,
    textTransform: 'uppercase',
    color: colors.mute,
  },
  pillValue: {
    fontFamily: mono,
    fontSize: 10,
    letterSpacing: 1,
    textTransform: 'uppercase',
  },
  barTrack: {
    height: 6,
    backgroundColor: 'rgba(198, 245, 74, 0.10)',
    marginTop: space.xs,
  },
  barFill: {
    height: 6,
  },
  empty: {
    color: colors.mute,
    fontSize: 14,
    paddingVertical: space.lg,
  },
})
