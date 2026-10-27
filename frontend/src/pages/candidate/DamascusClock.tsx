import { useEffect, useState } from 'react'
import { DAMASCUS_TIME_ZONE } from './candidateApi'

const LOCALE = 'ar-SY-u-nu-latn-ca-gregory'

const dateFormat = new Intl.DateTimeFormat(LOCALE, {
  timeZone: DAMASCUS_TIME_ZONE,
  weekday: 'long',
  day: 'numeric',
  month: 'long',
  year: 'numeric',
})

const timeFormat = new Intl.DateTimeFormat(LOCALE, { timeZone: DAMASCUS_TIME_ZONE, hour: 'numeric', minute: '2-digit' })

/**
 * Today's Gregorian date and the current time in Damascus, for information
 * only. It reads the device clock (no server polling), changes once a
 * minute, and is not a live region, so screen readers are not interrupted.
 */
export function DamascusClock() {
  const [now, setNow] = useState(() => new Date())

  useEffect(() => {
    let interval: number | undefined
    // Align updates to the start of each minute.
    const timeout = window.setTimeout(() => {
      setNow(new Date())
      interval = window.setInterval(() => setNow(new Date()), 60_000)
    }, 60_000 - (Date.now() % 60_000))
    return () => {
      window.clearTimeout(timeout)
      window.clearInterval(interval)
    }
  }, [])

  return (
    <p className="candidate-clock">
      <time className="candidate-clock__date" dateTime={now.toISOString()}>
        {dateFormat.format(now)}
      </time>
      <span className="candidate-clock__time">
        <span>{timeFormat.format(now)}</span> <span className="candidate-clock__zone">بتوقيت دمشق</span>
      </span>
    </p>
  )
}
