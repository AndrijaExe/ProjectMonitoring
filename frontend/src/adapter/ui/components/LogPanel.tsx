import { useState, type FormEvent } from 'react'
import { useGetProjectLogsQuery, useGetSystemLogsQuery } from '../../api/monitoringApi'
import { formatCheckedAt } from '../formatTime'
import { useSeeMore } from '../useSeeMore'
import { SeeMore } from './SeeMore'

type Props = {
  title: string
  /** Omitted for the monitor's own logs. */
  gameId?: string
}

type Query = {
  level: string
  text: string
}

const LEVELS = ['', 'error', 'warning', 'info'] as const

export function LogPanel({ title, gameId }: Props) {
  const [draft, setDraft] = useState<Query>({ level: '', text: '' })
  // Applied separately from the draft so typing does not fire a request per keystroke.
  const [applied, setApplied] = useState<Query>({ level: '', text: '' })

  const project = useGetProjectLogsQuery(
    { gameId: gameId ?? '', level: applied.level, text: applied.text },
    { skip: gameId == null },
  )
  const system = useGetSystemLogsQuery(
    { level: applied.level, text: applied.text },
    { skip: gameId != null },
  )
  const { data, isFetching, isError, refetch } = gameId != null ? project : system

  const lines = useSeeMore(data?.lines ?? [])

  const submit = (event: FormEvent) => {
    event.preventDefault()
    setApplied(draft)
  }

  return (
    <section className="logs">
      <div className="logs-head">
        <h2>{title}</h2>
        <form className="logs-filters" onSubmit={submit}>
          <select
            aria-label="Log level"
            value={draft.level}
            onChange={(event) => setDraft({ ...draft, level: event.target.value })}
          >
            {LEVELS.map((level) => (
              <option key={level || 'all'} value={level}>
                {level === '' ? 'all levels' : level}
              </option>
            ))}
          </select>
          <input
            aria-label="Search log text"
            placeholder="contains…"
            value={draft.text}
            onChange={(event) => setDraft({ ...draft, text: event.target.value })}
          />
          <button type="submit" disabled={isFetching}>
            Apply
          </button>
          <button type="button" disabled={isFetching} onClick={() => void refetch()}>
            {isFetching ? 'Reading…' : 'Refresh'}
          </button>
        </form>
      </div>

      {isError ? <p className="alert">The API could not answer the log request.</p> : null}
      {data?.note != null ? <p className="empty">{data.note}</p> : null}

      {data != null && data.note == null && data.lines.length === 0 ? (
        <p className="empty">Nothing in the last 24 hours for this filter.</p>
      ) : null}

      {lines.visible.length > 0 ? (
        <>
          <ol className="log-lines">
            {lines.visible.map((line, index) => (
              <li key={`${line.at}-${index}`} data-level={line.level ?? 'none'}>
                <span className="mono log-at">{formatCheckedAt(line.at)}</span>
                {line.level != null ? <span className="log-level">{line.level}</span> : null}
                <span className="log-message">{line.message}</span>
              </li>
            ))}
          </ol>
          <SeeMore
            hidden={lines.hidden}
            expanded={lines.expanded}
            onMore={lines.showMore}
            onLess={lines.showLess}
          />
        </>
      ) : null}
    </section>
  )
}
