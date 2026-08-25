import { useState } from 'react'
import {
  ActivityIndicator,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native'
import { useLazyGetSessionQuery } from '@shared/api/monitoringApi'
import { clearToken, setToken } from '@shared/store/authSlice'
import { useAppDispatch } from '@shared/store/hooks'
import { applyApiBaseUrl, currentApiBaseUrl } from '../apiHost'
import { Kicker } from '../components/parts'
import { colors, mono, space } from '../theme'

export function SignInScreen() {
  const dispatch = useAppDispatch()
  const [host, setHost] = useState(currentApiBaseUrl())
  const [token, setTokenText] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [checkSession, session] = useLazyGetSessionQuery()

  async function signIn() {
    const trimmed = token.trim()
    if (host.trim() === '' || trimmed === '') {
      setError('Both the host and the token are needed.')
      return
    }

    setError(null)
    await applyApiBaseUrl(host)
    // Before the check, because the client reads the token from the store to send it.
    dispatch(setToken(trimmed))

    // The session endpoint takes either token, so this says whether the secret is any good
    // without asking it to do anything. Its answer is cached, and the header reads the access
    // level from the same entry rather than asking again.
    const result = await checkSession()
    if (result.error) {
      dispatch(clearToken())
      setError(describe(result.error))
    }
  }

  return (
    <KeyboardAvoidingView
      style={styles.fill}
      behavior={Platform.OS === 'ios' ? 'padding' : undefined}
    >
      <ScrollView contentContainerStyle={styles.page} keyboardShouldPersistTaps="handled">
        <View style={styles.rule}>
          <Kicker>ProjectMonitoring</Kicker>
          <Text style={styles.title}>Sign in</Text>
          <Text style={styles.lede}>
            The read-only token reads the board and can ask for a fresh probe. It cannot stop a
            service, clear history or send mail, which is what makes it safe to keep on a phone.
          </Text>
        </View>

        <Text style={styles.label}>API host</Text>
        <TextInput
          style={styles.input}
          value={host}
          onChangeText={setHost}
          placeholder="https://monitoring-api.onrender.com"
          placeholderTextColor={colors.mute}
          autoCapitalize="none"
          autoCorrect={false}
          keyboardType="url"
          inputMode="url"
        />

        <Text style={styles.label}>Token</Text>
        <TextInput
          style={styles.input}
          value={token}
          onChangeText={setTokenText}
          placeholder="paste the token"
          placeholderTextColor={colors.mute}
          autoCapitalize="none"
          autoCorrect={false}
          secureTextEntry
          onSubmitEditing={signIn}
          returnKeyType="go"
        />

        <Pressable
          style={({ pressed }) => [styles.button, pressed && styles.buttonPressed]}
          onPress={signIn}
          disabled={session.isFetching}
        >
          {session.isFetching ? (
            <ActivityIndicator color={colors.ink} />
          ) : (
            <Text style={styles.buttonText}>Sign in</Text>
          )}
        </Pressable>

        {error === null ? null : <Text style={styles.error}>{error}</Text>}

        <Text style={styles.hint}>
          A free instance sleeps after a quarter of an hour, so the first sign-in can take the
          better part of a minute while it wakes.
        </Text>
      </ScrollView>
    </KeyboardAvoidingView>
  )
}

/**
 * Says which of the two went wrong, because "sign-in failed" sends the operator looking at the
 * token when the host is the part that is unreachable.
 */
function describe(error: unknown): string {
  const status = (error as { status?: unknown }).status

  if (status === 401 || status === 403) {
    return 'The token was rejected.'
  }
  if (status === 'FETCH_ERROR') {
    return 'No answer from that host. Check the URL, or give a sleeping instance a moment.'
  }
  if (status === 'PARSING_ERROR') {
    return 'That host answered, but not like the monitoring API. Check the URL.'
  }

  return 'Sign-in failed.'
}

const styles = StyleSheet.create({
  fill: {
    flex: 1,
    backgroundColor: colors.ink,
  },
  page: {
    padding: space.xl,
    paddingTop: 72,
  },
  rule: {
    borderLeftWidth: 3,
    borderLeftColor: colors.lime,
    paddingLeft: space.lg,
    marginBottom: space.xl,
  },
  title: {
    color: colors.paper,
    fontSize: 38,
    fontWeight: '500',
    letterSpacing: -1,
    marginTop: space.sm,
  },
  lede: {
    color: colors.mute,
    fontSize: 14,
    marginTop: space.md,
    lineHeight: 20,
  },
  label: {
    fontFamily: mono,
    fontSize: 11,
    letterSpacing: 1.6,
    textTransform: 'uppercase',
    color: colors.mute,
    marginBottom: space.xs,
    marginTop: space.lg,
  },
  input: {
    backgroundColor: colors.ink2,
    borderWidth: 1,
    borderColor: colors.line,
    color: colors.paper,
    paddingHorizontal: space.md,
    paddingVertical: space.md,
    fontSize: 15,
  },
  button: {
    backgroundColor: colors.lime,
    marginTop: space.xl,
    paddingVertical: space.md,
    alignItems: 'center',
  },
  buttonPressed: {
    opacity: 0.75,
  },
  buttonText: {
    color: colors.ink,
    fontWeight: '600',
    letterSpacing: 1.2,
    textTransform: 'uppercase',
    fontSize: 13,
  },
  error: {
    color: colors.alert,
    marginTop: space.lg,
    fontSize: 14,
  },
  hint: {
    color: colors.mute,
    fontSize: 12,
    marginTop: space.xl,
    lineHeight: 18,
  },
})
