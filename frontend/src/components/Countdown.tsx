import { confirmedSchedule } from '../content/site'
import { applicationPhaseAt, countdownTarget, deadlineText, periodText, phaseAt, remaining } from '../lib/schedule'
import type { PublicSchedule, SchedulePhase } from '../lib/schedule'
import { useServerNow } from '../lib/useSchedule'

const UNITS = [
  { key: 'days', label: 'يوماً' },
  { key: 'hours', label: 'ساعة' },
  { key: 'minutes', label: 'دقيقة' },
  { key: 'seconds', label: 'ثانية' },
] as const

const HEADINGS: Record<SchedulePhase, string> = {
  upcoming: 'يُفتح باب الترشح بعد',
  open: 'يغلق باب الترشح بعد',
  unavailable: 'الترشح غير متاح حالياً',
  closed: 'أُغلق باب الترشح',
  judging: 'أُغلق باب الترشح',
  judged: 'انتهت فترة التحكيم',
  unknown: 'مواعيد الدورة الأولى',
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
  const ready = schedule !== undefined && schedule !== null
  const now = useServerNow(offset, ready)
  const phase = ready ? phaseAt(now, schedule) : 'unknown'
  const application = applicationPhaseAt(now, schedule ?? null)
  const target = countdownTarget(phase, ready ? schedule : null)
  const left = target === null ? null : remaining(now, target)

  const deadline = ready && schedule.application?.closes_at ? deadlineText(schedule.application.closes_at) : null
  const judging = ready && schedule.judging ? periodText(schedule.judging.opens_at, schedule.judging.closes_at) : null
  const deadlineLine = deadline
    ? `${application === 'closed' ? 'أُغلق باب الترشح' : application === 'unavailable' ? 'الموعد المحدد لإغلاق باب الترشح' : 'يغلق باب الترشح'} ${deadline} — بتوقيت دمشق`
    : phase === 'unknown'
      ? confirmedSchedule.deadline
      : null
  const judgingLine = judging ? `فترة التحكيم: ${judging}` : phase === 'unknown' ? confirmedSchedule.judging : null

  return (
    <section className="countdown" data-phase={phase} aria-labelledby="countdown-title">
      <h2 id="countdown-title" className="countdown__title">
        {HEADINGS[phase]}
      </h2>

      {left && (
        <div className="countdown__units" role="timer" aria-label={phase === 'open' ? 'الوقت المتبقي حتى إغلاق باب الترشح' : 'الوقت المتبقي حتى فتح باب الترشح'}>
          {UNITS.map((unit) => (
            <div key={unit.key} className="countdown__unit">
              <span className="countdown__value">{String(left[unit.key]).padStart(2, '0')}</span>
              <span className="countdown__label">{unit.label}</span>
            </div>
          ))}
        </div>
      )}

      <dl className="countdown__dates">
        {deadlineLine && (
          <div className="countdown__date">
            <dt className="visually-hidden">موعد إغلاق باب الترشح</dt>
            <dd>{deadlineLine}</dd>
          </div>
        )}
        {judgingLine && (
          <div className="countdown__date">
            <dt className="visually-hidden">فترة التحكيم</dt>
            <dd>{judgingLine}</dd>
          </div>
        )}
      </dl>

      <p className="visually-hidden" aria-live="polite">
        {phase === 'unavailable' || phase === 'closed' || phase === 'judging' || phase === 'judged' ? HEADINGS[phase] : ''}
      </p>
    </section>
  )
}
