import Constants from 'expo-constants'
import * as Device from 'expo-device'
import * as Notifications from 'expo-notifications'
import { Platform } from 'react-native'

/**
 * Must match the channelId the backend sends. Android drops a notification naming a channel
 * the device has never created, without a word to anybody, so this is created before the app
 * can possibly have registered a token.
 */
const CHANNEL_ID = 'alerts'

/**
 * An alert that arrives while the operator is already looking at the board is still worth
 * showing: it is how they learn something changed under them mid-glance.
 */
Notifications.setNotificationHandler({
  handleNotification: async () => ({
    shouldPlaySound: true,
    shouldSetBadge: false,
    shouldShowBanner: true,
    shouldShowList: true,
  }),
})

export type PushRegistration =
  | { token: string; problem?: undefined }
  | { token?: undefined; problem: string }

/**
 * The token this run was given, kept so signing out can take the phone off the list. Held here
 * rather than in the store because it is not state anything renders from, and asking Expo for
 * it again while the operator waits to sign out would be the wrong moment for a round trip.
 */
let issued: string | null = null

export function issuedPushToken(): string | null {
  return issued
}

/**
 * Asks Android for permission and Expo for a push token.
 *
 * Every failure comes back as a sentence rather than an exception. Notifications are the part
 * of this app that depends on a Firebase project, an Expo project and a permission dialog, and
 * a board that refused to open because one of the three is missing would be a worse app than
 * one that says alerts are off.
 */
export async function registerForPush(): Promise<PushRegistration> {
  if (!Device.isDevice) {
    return { problem: 'Alerts need a real phone; an emulator cannot be pushed to.' }
  }

  try {
    if (Platform.OS === 'android') {
      await Notifications.setNotificationChannelAsync(CHANNEL_ID, {
        name: 'Alerts',
        // An outage is the reason this app exists, so it is allowed to make noise and to
        // appear over whatever is on screen.
        importance: Notifications.AndroidImportance.MAX,
        vibrationPattern: [0, 250, 250, 250],
        lightColor: '#c6f54a',
      })
    }

    if (!(await hasPermission())) {
      return { problem: 'Notifications are turned off for this app.' }
    }

    const projectId =
      Constants.expoConfig?.extra?.eas?.projectId ?? Constants.easConfig?.projectId
    if (typeof projectId !== 'string' || projectId === '') {
      return { problem: 'This build has no Expo project id, so it cannot be pushed to.' }
    }

    const { data } = await Notifications.getExpoPushTokenAsync({ projectId })
    issued = data

    return { token: data }
  } catch (error) {
    return { problem: error instanceof Error ? error.message : 'Could not register for alerts.' }
  }
}

/**
 * Android asks once and remembers the answer, so a refusal must not be re-asked on every
 * start: it would train the operator to dismiss the dialog.
 */
async function hasPermission(): Promise<boolean> {
  const existing = await Notifications.getPermissionsAsync()
  if (existing.granted) {
    return true
  }

  if (!existing.canAskAgain) {
    return false
  }

  return (await Notifications.requestPermissionsAsync()).granted
}
