import { useSite } from '../content/useSite'
import { useLocale } from '../i18n/useLocale'
import { applicationPhaseAt, countdownTarget, deadlineText, periodText, phaseAt, remaining } from '../lib/schedule'
import type { PublicSchedule, SchedulePhase } from '../lib/schedule'
import { useServerNow } from '../lib/useSchedule'

const UNITS = ['days', 'hours', 'minutes', 'seconds'] as const

const HEADINGS: Record<SchedulePhase, string> = {
  upcoming: 'countdown.upcoming',
  open: 'countdown.open',
  unavailable: 'countdown.unavailable',
  closed: 'countdown.closed',
  judging: 'countdown.closed',
  judged: 'countdown.judged',
  unknown: 'countdown.unknown',
}

/**
 * The application countdown, from the server's published schedule. It
 * counts down to the instant the server announced (days, hours, minutes
 * and seconds, never below zero), switches to «أُغلق باب الترشح» at that
 * instant, and to «انتهت فترة التحكيم» after the judging period; it claims
 * nothing about results. The full dates stay readable as plain text, and
 * only a change of phase is announced to screen readers, never each second.
 */
export function Countdown({ schedule, offset }: { schedule: PublicSchedule | null | undefined; offset: number }) {
  const { t, locale } = useLocale()
  const { confirmedSchedule } = useSite()
  const ready = schedule !== undefined && schedule !== null
  const now = useServerNow(offset, ready)
  const phase = ready ? phaseAt(now, schedule) : 'unknown'
  const application = applicationPhaseAt(now, schedule ?? null)
  const target = countdownTarget(phase, ready ? schedule : null)
  const left = target === null ? null : remaining(now, target)

  const deadline = ready && schedule.application?.closes_at ? deadlineText(schedule.application.closes_at, locale) : null
  const judging = ready && schedule.judging ? periodText(schedule.judging.opens_at, schedule.judging.closes_at, locale) : null
  const deadlineKey = application === 'closed' ? 'countdown.lineClosed' : application === 'unavailable' ? 'countdown.lineUnavailable' : 'countdown.lineOpen'
  const deadlineLine = deadline ? t(deadlineKey, { date: deadline }) : phase === 'unknown' ? confirmedSchedule.deadline : null
  const judgingLine = judging ? t('countdown.judgingLine', { period: judging }) : phase === 'unknown' ? confirmedSchedule.judging : null

  return (
    <section className="countdown" data-phase={phase} aria-labelledby="countdown-title">
      <h2 id="countdown-title" className="countdown__title">
        {t(HEADINGS[phase])}
      </h2>

      {left && (
        <div className="countdown__units" role="timer" aria-label={phase === 'open' ? t('countdown.timerOpen') : t('countdown.timerUpcoming')}>
          {UNITS.map((unit) => (
            <div key={unit} className="countdown__unit">
              <span className="countdown__value">{String(left[unit]).padStart(2, '0')}</span>
              <span className="countdown__label">{t(`countdown.${unit}`)}</span>
            </div>
          ))}
        </div>
      )}

      <dl className="countdown__dates">
        {deadlineLine && (
          <div className="countdown__date">
            <dt className="visually-hidden">{t('countdown.deadlineLabel')}</dt>
            <dd>{deadlineLine}</dd>
          </div>
        )}
        {judgingLine && (
          <div className="countdown__date">
            <dt className="visually-hidden">{t('countdown.judgingLabel')}</dt>
            <dd>{judgingLine}</dd>
          </div>
        )}
      </dl>

      <p className="visually-hidden" aria-live="polite">
        {phase === 'unavailable' || phase === 'closed' || phase === 'judging' || phase === 'judged' ? t(HEADINGS[phase]) : ''}
      </p>
    </section>
  )
}
