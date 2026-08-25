import type { NativeStackScreenProps } from '@react-navigation/native-stack'

/**
 * Two screens: the board, and one project. The display name travels with the route so the
 * header has a title before the detail request comes back.
 */
export type RootStackParams = {
  Fleet: undefined
  Project: { gameId: string; displayName: string }
}

export type ScreenProps<T extends keyof RootStackParams> = NativeStackScreenProps<
  RootStackParams,
  T
>
