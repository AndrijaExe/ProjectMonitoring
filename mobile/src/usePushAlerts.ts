import { useEffect, useState } from 'react'
import { Platform } from 'react-native'
import { useRegisterDeviceMutation } from '@shared/api/monitoringApi'
import { registerForPush } from './push'

/**
 * Tells the API where to reach this phone, once per sign-in.
 *
 * Registering on every start rather than only the first is deliberate: the operating system
 * can retire a push route whenever it likes, and the server drops any route Expo reports as
 * gone. Saying "still here" on each start is what brings a phone back after that.
 *
 * @returns a sentence to show when alerts will not work, or null when they will
 */
export function usePushAlerts(): string | null {
  const [problem, setProblem] = useState<string | null>(null)
  const [registerDevice] = useRegisterDeviceMutation()

  useEffect(() => {
    let cancelled = false

    async function announce(): Promise<void> {
      const registration = await registerForPush()
      if (cancelled) {
        return
      }

      if (registration.problem !== undefined) {
        setProblem(registration.problem)

        return
      }

      try {
        await registerDevice({ token: registration.token, platform: Platform.OS }).unwrap()
        if (!cancelled) {
          setProblem(null)
        }
      } catch {
        if (!cancelled) {
          // The board still works. Only the interruption is lost, and saying which is better
          // than a silence the operator would read as "alerts are on".
          setProblem('The API did not accept this phone, so alerts will not arrive.')
        }
      }
    }

    void announce()

    return () => {
      cancelled = true
    }
  }, [registerDevice])

  return problem
}
