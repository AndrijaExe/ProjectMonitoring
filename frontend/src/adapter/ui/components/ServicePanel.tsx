import { useEffect, useState } from 'react'
import { useControlServiceMutation, useGetServiceStatusQuery } from '../../api/monitoringApi'
import type { ServiceAction, ServiceState } from '../../../model/monitoring'
import { formatCheckedAt } from '../formatTime'

type Props = {
  gameId: string
  displayName: string
}

const SETTLED_MS = 60_000
const WATCHING_MS = 10_000
/** How long to keep waiting for the host to admit a change before letting the buttons go again. */
const PATIENCE_MS = 45_000

/** Whether the host is finally reporting what was asked of it. */
function landed(action: ServiceAction, state: ServiceState): boolean {
  switch (action) {
    case 'stop':
      return state.stopped
    case 'start':
      return !state.stopped
    case 'rebuild':
      return state.busy
  }
}

/**
 * The run state of the target's service, and the three buttons that change it.
 *
 * Everything else in this console reports. This panel acts, so it is deliberately plain: no
 * icons to misread, one sentence per state, and a question before anything that takes the game
 * off the air.
 */
export function ServicePanel({ gameId, displayName }: Props) {
  // A deploy takes minutes and its progress is the one thing an operator waits on, so a service
  // mid-change is asked about often and a settled one is left alone. Every ask is a call to the
  // host, which counts them.
  const [pollMs, setPollMs] = useState(SETTLED_MS)
  const { data, isLoading, isError } = useGetServiceStatusQuery(gameId, {
    pollingInterval: pollMs,
  })
  const [control, result] = useControlServiceMutation()
  // Accepted by the host, not yet visible in what it reports. Render answers these calls before
  // the change takes effect, so without remembering the request the panel would re-enable the
  // buttons over a state that is already out of date and invite the same press twice.
  const [pending, setPending] = useState<ServiceAction | null>(null)

  const state = data?.state ?? null
  const waiting = pending !== null

  useEffect(() => {
    setPollMs(state?.busy === true || waiting ? WATCHING_MS : SETTLED_MS)
  }, [state?.busy, waiting])

  useEffect(() => {
    if (pending !== null && state !== null && landed(pending, state)) {
      setPending(null)
    }
  }, [pending, state])

  useEffect(() => {
    if (pending === null) {
      return
    }

    // A rebuild can finish between two reads, and a host can simply never agree. Either way the
    // panel gives up waiting rather than staying disabled for the rest of the day.
    const timer = window.setTimeout(() => setPending(null), PATIENCE_MS)
    return () => window.clearTimeout(timer)
  }, [pending])

  const acting = result.isLoading
  const canAct = (data?.enabled ?? false) && state != null && !acting && !waiting

  const press = (action: ServiceAction, question: string) => {
    if (window.confirm(question)) {
      control({ gameId, action })
        .unwrap()
        .then(() => setPending(action))
        // Already reported below; this only keeps the rejection from going unhandled.
        .catch(() => undefined)
    }
  }

  return (
    <section className="service">
      <div className="service-head">
        <h2>
          Service
          {state != null ? <span className="logs-source mono">{state.name}</span> : null}
        </h2>
        <div className="service-actions">
          <button
            type="button"
            className="ghost"
            disabled={!canAct || state?.busy === true}
            onClick={() =>
              press(
                'rebuild',
                `Build ${displayName} again from the latest commit? It keeps serving from the current build until the new one is up.`,
              )
            }
          >
            Rebuild
          </button>
          {state?.stopped === true ? (
            <button
              type="button"
              disabled={!canAct}
              onClick={() => press('start', `Start ${displayName} again?`)}
            >
              Start
            </button>
          ) : (
            <button
              type="button"
              className="danger"
              disabled={!canAct || state?.busy === true}
              onClick={() =>
                press(
                  'stop',
                  // Spelled out because the point of the button is that it has consequences.
                  `Stop ${displayName}? It answers nothing until someone starts it: no logins, no chat, no telemetry. The probes will report it down, and you will get the usual mail about that.`,
                )
              }
            >
              Stop
            </button>
          )}
        </div>
      </div>

      {isLoading ? <p className="empty">Asking the host…</p> : null}
      {acting ? <p className="empty">Asked. Waiting for the host to answer…</p> : null}
      {waiting && !acting ? (
        // Named, because a panel that goes quiet after a press reads as a press that did nothing.
        <p className="empty">
          The host accepted the {pending}. It still reports the state below, which is normal for a
          minute.
        </p>
      ) : null}
      {isError ? <p className="alert">The API could not read the run state.</p> : null}
      {data?.note != null ? <p className="empty">{data.note}</p> : null}
      {result.isError ? (
        // Nothing changed, and saying so beats leaving the operator to guess whether it did.
        <p className="alert">{errorText(result.error)}</p>
      ) : null}

      {state != null ? <Details state={state} /> : null}
    </section>
  )
}

function Details({ state }: { state: ServiceState }) {
  return (
    <>
      <p className={state.stopped || state.failed ? 'alert' : 'empty'}>{state.summary}</p>
      <ul className="totals">
        <li>
          <span>last deploy</span>
          <span className="mono">{state.deploy_status || '—'}</span>
        </li>
        <li>
          <span>when</span>
          <span className="mono">{formatCheckedAt(state.deploy_at)}</span>
        </li>
        {state.commit !== '' ? (
          <li>
            <span>commit</span>
            <span className="note">{state.commit}</span>
          </li>
        ) : null}
      </ul>
      {state.stopped ? (
        // The reason someone would leave it stopped, said where they decide whether to.
        <p className="meta">
          Stopped costs nothing and the game handles it: players get connection errors instead of
          chat, and nothing on the host runs up a bill.
        </p>
      ) : null}
    </>
  )
}

function errorText(error: unknown): string {
  const data = (error as { data?: { error?: string } } | undefined)?.data
  return data?.error ?? 'The host refused the change. Nothing was altered.'
}
