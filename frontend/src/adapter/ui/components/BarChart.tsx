export type BarPart = {
  id: string
  value: number
  color: string
  label: string
}

export type BarPoint = {
  key: string
  /** Under the bar; may be dropped when the chart is crowded. */
  label: string
  /** In the tooltip, in full. */
  title: string
  parts: BarPart[]
  /** The bar still filling — drawn hatched so it is not read as a low result. */
  open?: boolean
}

type Props = {
  title: string
  points: BarPoint[]
  format: (value: number) => string
}

const WIDTH = 720
const HEIGHT = 200
const LEFT = 56
const RIGHT = 12
const TOP = 14
const BOTTOM = 30

/** Stacked bars in the console's own SVG — no chart library, no fetch, the same in a screenshot. */
export function BarChart({ title, points, format }: Props) {
  const max = Math.max(
    ...points.map((point) => point.parts.reduce((sum, part) => sum + part.value, 0)),
    0,
  )

  if (points.length === 0 || max <= 0) {
    return <p className="empty">Nothing counted in this range yet.</p>
  }

  const innerWidth = WIDTH - LEFT - RIGHT
  const innerHeight = HEIGHT - TOP - BOTTOM
  const slot = innerWidth / points.length
  const gap = Math.min(6, slot * 0.25)
  const barWidth = Math.max(4, slot - gap)
  // Labels every n-th bar so thirty days do not print on top of each other.
  const labelEvery = points.length > 16 ? 5 : points.length > 8 ? 2 : 1

  return (
    <svg className="usage-chart" viewBox={`0 0 ${WIDTH} ${HEIGHT}`} role="img">
      <title>{title}</title>
      <defs>
        <pattern id="open-bucket" width="6" height="6" patternUnits="userSpaceOnUse" patternTransform="rotate(45)">
          <rect width="3" height="6" fill="rgba(220, 234, 212, 0.55)" />
        </pattern>
      </defs>
      {[0, 0.5, 1].map((tick) => {
        const y = TOP + innerHeight * (1 - tick)
        return (
          <g key={tick}>
            <line x1={LEFT} x2={WIDTH - RIGHT} y1={y} y2={y} stroke="rgba(198, 245, 74, 0.12)" />
            <text x={LEFT - 8} y={y + 4} textAnchor="end" className="usage-axis">
              {format(max * tick)}
            </text>
          </g>
        )
      })}
      {points.map((point, index) => {
        const x = LEFT + slot * index + gap / 2
        let y = TOP + innerHeight
        const total = point.parts.reduce((sum, part) => sum + part.value, 0)

        return (
          <g key={point.key}>
            <title>{`${point.title} · ${format(total)}`}</title>
            {point.parts.map((part) => {
              const barHeight = (part.value / max) * innerHeight
              if (barHeight <= 0) {
                return null
              }
              y -= barHeight
              return (
                <rect
                  key={part.id}
                  x={x}
                  y={y}
                  width={barWidth}
                  height={barHeight}
                  fill={part.color}
                  opacity={point.open ? 0.55 : 1}
                />
              )
            })}
            {point.open && total > 0 ? (
              <rect
                x={x}
                y={y}
                width={barWidth}
                height={TOP + innerHeight - y}
                fill="url(#open-bucket)"
              />
            ) : null}
            {index % labelEvery === 0 || index === points.length - 1 ? (
              <text x={x + barWidth / 2} y={HEIGHT - 9} textAnchor="middle" className="usage-axis">
                {point.label}
              </text>
            ) : null}
          </g>
        )
      })}
    </svg>
  )
}
