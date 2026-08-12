import { createSlice, type PayloadAction } from '@reduxjs/toolkit'

const STORAGE_KEY = 'pm.adminToken'

type AuthState = {
  token: string | null
}

const initialState: AuthState = {
  token: sessionStorage.getItem(STORAGE_KEY),
}

const authSlice = createSlice({
  name: 'auth',
  initialState,
  reducers: {
    setToken(state, action: PayloadAction<string>) {
      state.token = action.payload
      sessionStorage.setItem(STORAGE_KEY, action.payload)
    },
    clearToken(state) {
      state.token = null
      sessionStorage.removeItem(STORAGE_KEY)
    },
  },
})

export const { setToken, clearToken } = authSlice.actions
export const authReducer = authSlice.reducer
