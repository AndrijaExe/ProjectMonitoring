import type { ReportPeriod } from '@shared/model/monitoring'

const PERIODS: { id: ReportPeriod; label: string }[] = [
  { id: 'day', label: 'Daily' },
  { id: 'week', label: 'Weekly' },
  { id: 'month', label: 'Monthly' },
]

type Props = {
  value: ReportPeriod
  onChange: (period: ReportPeriod) => void
}

/** The grain every number on the page is cut at. One control, so the tiles, the chart and the table never disagree. */
export function PeriodSwitch({ value, onChange }: Props) {
  return (
    <div className="period-switch" role="group" aria-label="Report period">
      {PERIODS.map((period) => (
        <button
          key={period.id}
          type="button"
          className={value === period.id ? 'period period-active' : 'period'}
          aria-pressed={value === period.id}
          onClick={() => onChange(period.id)}
        >
          {period.label}
        </button>
      ))}
    </div>
  )
}
