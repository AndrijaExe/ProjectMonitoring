import { useEffect, useState } from 'react'
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native'
import { StatusBar } from 'expo-status-bar'
import { SafeAreaProvider } from 'react-native-safe-area-context'
import { NavigationContainer, type Theme } from '@react-navigation/native'
import { createNativeStackNavigator } from '@react-navigation/native-stack'
import { Provider } from 'react-redux'
import { monitoringApi, useGetSessionQuery } from '@shared/api/monitoringApi'
import { clearToken, tokenRestored } from '@shared/store/authSlice'
import { useAppDispatch, useAppSelector } from '@shared/store/hooks'
import { store } from '@shared/store/store'
import { persistToken } from '@shared/store/tokenPersistence'
import { restoreApiBaseUrl } from './src/apiHost'
import { readStoredToken, secureTokenStore } from './src/secureStorage'
import { FleetScreen } from './src/screens/FleetScreen'
import { ProjectScreen } from './src/screens/ProjectScreen'
import { SignInScreen } from './src/screens/SignInScreen'
import type { RootStackParams } from './src/navigation'
import { colors, mono, space } from './src/theme'

const Stack = createNativeStackNavigator<RootStackParams>()

const navigationTheme: Theme = {
  dark: true,
  colors: {
    primary: colors.lime,
    background: colors.ink,
    card: colors.ink,
    text: colors.paper,
    border: colors.line,
    notification: colors.alert,
  },
  fonts: {
    regular: { fontFamily: 'System', fontWeight: '400' },
    medium: { fontFamily: 'System', fontWeight: '500' },
    bold: { fontFamily: 'System', fontWeight: '600' },
    heavy: { fontFamily: 'System', fontWeight: '700' },
  },
}

export default function App() {
  return (
    <Provider store={store}>
      <SafeAreaProvider>
        <StatusBar style="light" />
        <Root />
      </SafeAreaProvider>
    </Provider>
  )
}

function Root() {
  const dispatch = useAppDispatch()
  const token = useAppSelector((state) => state.auth.token)
  const restored = useAppSelector((state) => state.auth.restored)
  const [hostReady, setHostReady] = useState(false)

  useEffect(() => {
    let cancelled = false

    async function boot() {
      // The host first: the client needs somewhere to send the very first request, and the
      // token is worthless without it.
      await restoreApiBaseUrl()
      const stored = await readStoredToken()
      if (cancelled) {
        return
      }

      dispatch(tokenRestored(stored))
      setHostReady(true)
    }

    void boot()
    // Writes every later change back to the keychain.
    const stop = persistToken(store, secureTokenStore)

    return () => {
      cancelled = true
      stop()
    }
  }, [dispatch])

  // The keychain answers in its own time, and a sign-in form flashed at an operator who is
  // already signed in is worse than a blank moment.
  if (!hostReady || !restored) {
    return (
      <View style={styles.splash}>
        <ActivityIndicator color={colors.lime} />
      </View>
    )
  }

  if (token === null) {
    return <SignInScreen />
  }

  return (
    <NavigationContainer theme={navigationTheme}>
      <Stack.Navigator
        screenOptions={{
          headerStyle: { backgroundColor: colors.ink },
          headerTintColor: colors.lime,
          headerTitleStyle: { color: colors.paper },
          headerShadowVisible: false,
          contentStyle: { backgroundColor: colors.ink },
        }}
      >
        <Stack.Screen
          name="Fleet"
          component={FleetScreen}
          options={{
            title: 'ProjectMonitoring',
            headerTitleStyle: styles.brand,
            headerRight: () => <SignOutButton />,
          }}
        />
        <Stack.Screen
          name="Project"
          component={ProjectScreen}
          options={({ route }) => ({ title: route.params.displayName })}
        />
      </Stack.Navigator>
    </NavigationContainer>
  )
}

function SignOutButton() {
  const dispatch = useAppDispatch()
  // Read from the session the sign-in screen already asked for, rather than kept as state of
  // its own, so a token restored from the keychain on a cold start is described correctly too.
  const session = useGetSessionQuery()

  return (
    <View style={styles.headerRight}>
      {/* Which token is on this phone is worth saying: one of them can take a service down. */}
      {session.data === undefined ? null : (
        <Text style={[styles.access, !session.data.readonly && styles.accessFull]}>
          {session.data.readonly ? 'read-only' : 'full access'}
        </Text>
      )}
      <Pressable
        onPress={() => {
          dispatch(clearToken())
          dispatch(monitoringApi.util.resetApiState())
        }}
      >
        <Text style={styles.signOut}>Sign out</Text>
      </Pressable>
    </View>
  )
}

const styles = StyleSheet.create({
  splash: {
    flex: 1,
    backgroundColor: colors.ink,
    alignItems: 'center',
    justifyContent: 'center',
  },
  brand: {
    fontFamily: mono,
    fontSize: 13,
    letterSpacing: 1.6,
    color: colors.lime,
  },
  headerRight: {
    alignItems: 'flex-end',
  },
  access: {
    fontFamily: mono,
    fontSize: 9,
    letterSpacing: 1,
    textTransform: 'uppercase',
    color: colors.mute,
  },
  accessFull: {
    color: colors.warn,
  },
  signOut: {
    fontFamily: mono,
    fontSize: 11,
    letterSpacing: 1,
    textTransform: 'uppercase',
    color: colors.paper,
    paddingVertical: space.xs,
  },
})
