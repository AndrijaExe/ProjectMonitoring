import { useState, type FormEvent } from 'react'
import { Navigate } from 'react-router-dom'
import { useLoginMutation } from '@shared/api/monitoringApi'
import { setToken } from '@shared/store/authSlice'
import { useAppDispatch, useAppSelector } from '@shared/store/hooks'

export function LoginPage() {
  const token = useAppSelector((state) => state.auth.token)
  const dispatch = useAppDispatch()
  const [login, { isLoading, isError }] = useLoginMutation()
  const [value, setValue] = useState('')

  if (token) {
    return <Navigate to="/" replace />
  }

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    const nextToken = value.trim()
    try {
      await login(nextToken).unwrap()
      dispatch(setToken(nextToken))
    } catch {
      // RTK Query surfaces isError
    }
  }

  return (
    <div className="login-shell">
      <p className="brand-mark">ProjectMonitoring</p>
      <p className="kicker">Operator desk</p>
      <h1>Watch the fleet without opening logs.</h1>
      <p className="lede">
        One token opens health, ready probes, and ingest counters for every registered game.
      </p>
      <form className="login-form" onSubmit={onSubmit}>
        <label htmlFor="admin-token">Admin token</label>
        <input
          id="admin-token"
          type="password"
          autoComplete="current-password"
          value={value}
          onChange={(event) => setValue(event.target.value)}
          required
          minLength={16}
        />
        <button type="submit" disabled={isLoading}>
          {isLoading ? 'Checking…' : 'Open console'}
        </button>
        {isError ? <p className="alert">Token rejected.</p> : null}
      </form>
    </div>
  )
}
