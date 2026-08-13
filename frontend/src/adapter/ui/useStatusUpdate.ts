import { useState } from 'react'
import { wakeTargets } from './wakeTargets'

type Phase = 'idle' | 'waking' | 'polling'

const LABELS: Record<Phase, string> = {
  idle: 'Update status',
  waking: 'Waking…',
  polling: 'Updating…',
}

/**
 * Wakes the targets, then asks the API to probe them.
 *
 * Waking first is not an optimisation. A probe sent to a sleeping free instance comes back
 * rate limited, which tells the operator nothing about the game.
 */
export function useStatusUpdate(poll: () => Promise<unknown>) {
  const [phase, setPhase] = useState<Phase>('idle')

  async function update(urls: string[]): Promise<void> {
    setPhase('waking')
    try {
      await wakeTargets(urls)
      setPhase('polling')
      await poll()
    } finally {
      setPhase('idle')
    }
  }

  return { busy: phase !== 'idle', label: LABELS[phase], update }
}
