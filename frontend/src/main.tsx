import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { Provider } from 'react-redux'
import { setApiBaseUrl } from '@shared/api/config'
import { store } from '@shared/store/store'
import { tokenRestored } from '@shared/store/authSlice'
import { persistToken } from '@shared/store/tokenPersistence'
import { readStoredToken, sessionTokenStore } from './adapter/store/tokenStorage'
import App from './App'
import './index.css'

// The shared client cannot read a Vite variable, so the host is handed to it here. Empty means
// same origin, which is what the dev proxy and a single-host deploy both want.
setApiBaseUrl(import.meta.env.VITE_API_BASE_URL ?? '')

// Before the first render, so a signed-in operator never sees the sign-in form flash past.
store.dispatch(tokenRestored(readStoredToken()))
persistToken(store, sessionTokenStore)

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <Provider store={store}>
      <App />
    </Provider>
  </StrictMode>,
)
