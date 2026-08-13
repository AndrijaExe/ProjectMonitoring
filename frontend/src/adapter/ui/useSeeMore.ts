import { useState } from 'react'

const STEP = 5

/**
 * Shows a short list by default. A board that opens with forty rows of history buries the
 * only line that usually matters, which is the newest one.
 */
export function useSeeMore<T>(items: T[], step: number = STEP) {
  const [limit, setLimit] = useState(step)

  const visible = items.slice(0, limit)
  const hidden = Math.max(0, items.length - visible.length)

  return {
    visible,
    hidden,
    expanded: limit > step,
    showMore: () => setLimit((current) => current + step),
    showLess: () => setLimit(step),
  }
}
