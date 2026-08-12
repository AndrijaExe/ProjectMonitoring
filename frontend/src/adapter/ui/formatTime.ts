export function formatCheckedAt(value: string | null | undefined): string {
  if (!value) {
    return 'never polled'
  }

  const then = Date.parse(value)
  if (Number.isNaN(then)) {
    return value
  }

  const deltaSeconds = Math.max(0, Math.round((Date.now() - then) / 1000))
  if (deltaSeconds < 10) {
    return 'just now'
  }
  if (deltaSeconds < 60) {
    return `${deltaSeconds}s ago`
  }
  const minutes = Math.round(deltaSeconds / 60)
  if (minutes < 60) {
    return `${minutes}m ago`
  }

  return new Date(then).toLocaleString()
}
