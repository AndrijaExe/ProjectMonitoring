import { createSlice, type PayloadAction } from '@reduxjs/toolkit'

export type AuthState = {
  token: string | null
  /**
   * False until the stored token has been looked for. The browser answers that question
   * instantly, a phone's keychain does not, and a screen that cannot tell "no token" from
   * "not asked yet" flashes the sign-in form at an operator who is already signed in.
   */
  restored: boolean
}

const initialState: AuthState = {
  token: null,
  restored: false,
}

/**
 * Holds the token, and nothing else. Where it is kept between runs is the platform's business:
 * see persistToken, which the web console backs with sessionStorage and the phone app with the
 * device keychain.
 */
const authSlice = createSlice({
  name: 'auth',
  initialState,
  reducers: {
    setToken(state, action: PayloadAction<string>) {
      state.token = action.payload
      state.restored = true
    },
    clearToken(state) {
      state.token = null
      state.restored = true
    },
    /** The answer from storage at start-up, token or not. */
    tokenRestored(state, action: PayloadAction<string | null>) {
      state.token = action.payload
      state.restored = true
    },
  },
})

export const { setToken, clearToken, tokenRestored } = authSlice.actions
export const authReducer = authSlice.reducer
