import { Platform } from 'react-native'
import type { HealthStatus } from '@shared/model/monitoring'

/**
 * The console's palette, so the phone reads as the same instrument rather than a second
 * opinion. Kept as plain values because there is no CSS here to share.
 */
export const colors = {
  ink: '#08110d',
  ink2: '#0e1a14',
  paper: '#dcead4',
  mute: '#7f9486',
  line: 'rgba(198, 245, 74, 0.18)',
  lime: '#c6f54a',
  alert: '#ff6b4a',
  warn: '#f0c14a',
} as const

export const mono = Platform.select({ ios: 'Menlo', android: 'monospace', default: 'monospace' })

export const space = {
  xs: 4,
  sm: 8,
  md: 12,
  lg: 16,
  xl: 24,
} as const

/**
 * Green is running, amber is answering but not well, red is not answering, grey is unheard of.
 * Amber covers throttled too: a rate limited probe measured nothing, which is not the same as
 * an outage and must not be shown as one.
 */
export function statusColor(status: HealthStatus): string {
  switch (status) {
    case 'ok':
    case 'ready':
      return colors.lime
    case 'down':
      return colors.alert
    case 'not_ready':
    case 'timeout':
    case 'throttled':
    case 'error':
      return colors.warn
    default:
      return colors.mute
  }
}
