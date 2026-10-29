import { useEffect, useState } from 'react'
import { api } from './api'
import { clockOffset } from './schedule'
import type { PublicSchedule } from './schedule'

type Loaded = { schedule: PublicSchedule | null; offset: number; failed: boolean }

let pending: Promise<Loaded> | null = null
let loadedAt = 0

/** One request per minute at most, shared by every component that asks. */
function load(): Promise<Loaded> {
  if (pending && Date.now() - loadedAt < 60_000) return pending
  loadedAt = Date.now()
  const sentAt = Date.now()
  pending = api
    .get<{ data: PublicSchedule }>('/api/public/schedule')
    .then((response) => ({ schedule: response.data, offset: clockOffset(response.data.server_time, sentAt, Date.now()), failed: false }))
    .catch(() => ({ schedule: null, offset: 0, failed: true }))

  return pending
}

/**
 * The public schedule and the difference between the server's clock and
 * this device's (so a wrong device clock does not move the countdown).
 * `schedule` is undefined while loading.
 */
export function useSchedule(): { schedule: PublicSchedule | null | undefined; offset: number; failed: boolean } {
  const [state, setState] = useState<Loaded | undefined>(undefined)

  useEffect(() => {
    let active = true
    void load().then((loaded) => {
      if (active) setState(loaded)
    })
    return () => {
      active = false
    }
  }, [])

  return { schedule: state?.schedule, offset: state?.offset ?? 0, failed: state?.failed ?? false }
}

/**
 * The current time aligned to the server, refreshed every second while
 * `running`, and at once when the tab becomes visible again (timers are
 * throttled in background tabs). The timer is cleaned up on unmount.
 */
export function useServerNow(offset: number, running: boolean): number {
  const [now, setNow] = useState(() => Date.now() + offset)

  useEffect(() => {
    const update = () => setNow(Date.now() + offset)
    update()
    if (!running) return
    const timer = window.setInterval(update, 1000)
    const onVisibility = () => {
      if (document.visibilityState === 'visible') update()
    }
    document.addEventListener('visibilitychange', onVisibility)
    return () => {
      window.clearInterval(timer)
      document.removeEventListener('visibilitychange', onVisibility)
    }
  }, [offset, running])

  return now
}
