import type { ReactNode } from 'react'
import { NavLink } from 'react-router-dom'
import { clearToken } from '../../store/authSlice'
import { useAppDispatch } from '../../store/hooks'

type ShellProps = {
  children: ReactNode
  kicker?: ReactNode
  title: string
  lede?: string
  action?: ReactNode
}

export function Shell({ children, kicker, title, lede, action }: ShellProps) {
  const dispatch = useAppDispatch()

  return (
    <div className="shell">
      <header className="mast">
        <NavLink className="brand" to="/">
          ProjectMonitoring
        </NavLink>
        <button className="ghost" type="button" onClick={() => dispatch(clearToken())}>
          Sign out
        </button>
      </header>
      <section className="hero">
        <div>
          {kicker ? <p className="kicker">{kicker}</p> : null}
          <h1>{title}</h1>
          {lede ? <p className="lede">{lede}</p> : null}
        </div>
        {action}
      </section>
      {children}
    </div>
  )
}
