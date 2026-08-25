import { Navigate, Outlet } from 'react-router-dom'
import { useAppSelector } from '@shared/store/hooks'

export function RequireAuth() {
  const token = useAppSelector((state) => state.auth.token)
  if (!token) {
    return <Navigate to="/login" replace />
  }

  return <Outlet />
}
