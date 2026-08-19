export type ProjectTabId = 'health' | 'usage' | 'logs'

const TABS: { id: ProjectTabId; label: string }[] = [
  { id: 'health', label: 'Health' },
  { id: 'usage', label: 'Usage' },
  { id: 'logs', label: 'Logs' },
]

type Props = {
  active: ProjectTabId
  onChange: (id: ProjectTabId) => void
}

/**
 * The three things an operator comes here to look at, kept apart so a page of probes
 * does not bury the spend or the lines that explain it.
 */
export function ProjectTabs({ active, onChange }: Props) {
  return (
    <nav className="tabs" aria-label="Project sections">
      {TABS.map((tab) => (
        <button
          key={tab.id}
          type="button"
          className={active === tab.id ? 'tab tab-active' : 'tab'}
          aria-current={active === tab.id ? 'page' : undefined}
          onClick={() => onChange(tab.id)}
        >
          {tab.label}
        </button>
      ))}
    </nav>
  )
}
