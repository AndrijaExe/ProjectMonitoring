import { configureStore } from '@reduxjs/toolkit'
import { monitoringApi } from '../api/monitoringApi'
import { authReducer } from './authSlice'

export const store = configureStore({
  reducer: {
    auth: authReducer,
    [monitoringApi.reducerPath]: monitoringApi.reducer,
  },
  middleware: (getDefaultMiddleware) =>
    getDefaultMiddleware().concat(monitoringApi.middleware),
})

export type AppStore = typeof store
export type RootState = ReturnType<typeof store.getState>
export type AppDispatch = typeof store.dispatch
