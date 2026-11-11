import type { JourneyPhase } from '../../content/site'
import { pageAt } from '../../content/site'
import { useSite } from '../../content/useSite'
import { useLocale } from '../../i18n/useLocale'
import { applicationPhaseAt, deadlineText, judgingPhaseAt, periodText } from '../../lib/schedule'
import type { PublicSchedule } from '../../lib/schedule'
import { usePageMeta } from '../../lib/usePageMeta'
import { useSchedule, useServerNow } from '../../lib/useSchedule'
import { PageHeader } from './PageHeader'
import './public.css'

type Status = { key: string; tone: 'planned' | 'current' | 'done' | 'neutral' }

/**
 * A phase's status, from the published schedule only: the application and
 * judging periods are «جارية» or «انتهت» by their dates; the other phases
 * carry no completion claim (planned phases say so).
 */
function statusOf(key: JourneyPhase['key'], now: number, schedule: PublicSchedule | null | undefined): Status | null {
  const application = applicationPhaseAt(now, schedule ?? null)
  const judging = judgingPhaseAt(now, schedule ?? null)

  switch (key) {
    case 'application':
      if (application === 'open') return { key: 'journey.status.current', tone: 'current' }
      if (application === 'upcoming') return { key: 'journey.status.upcoming', tone: 'planned' }
      if (application === 'unavailable') return { key: 'journey.status.unavailable', tone: 'neutral' }
      if (application === 'closed') return { key: 'journey.status.ended', tone: 'done' }
      return null
    case 'judging':
      if (judging === 'ended') return { key: 'journey.status.ended', tone: 'done' }
      if (judging === 'current') return { key: 'journey.status.current', tone: 'current' }
      if (judging === 'upcoming') return { key: 'journey.status.upcoming', tone: 'planned' }
      return null
    case 'recognition':
      return { key: 'journey.status.planned', tone: 'planned' }
    case 'screening':
      return { key: 'journey.status.screening', tone: 'neutral' }
    default:
      return null
  }
}

export function JourneyPage() {
  const { award, confirmedSchedule, cycle, journey, pages } = useSite()
  const { t, locale } = useLocale()
  const page = pageAt(pages, '/journey')
  usePageMeta(page.label, page.description)
  const { schedule, offset } = useSchedule()
  const now = useServerNow(offset, Boolean(schedule))
  const application = applicationPhaseAt(now, schedule ?? null)

  const closes = schedule?.application?.closes_at ? deadlineText(schedule.application.closes_at, locale) : null
  const judging = schedule?.judging ? periodText(schedule.judging.opens_at, schedule.judging.closes_at, locale) : null
  const deadlineKey = application === 'closed' ? 'countdown.lineClosed' : application === 'unavailable' ? 'countdown.lineUnavailable' : 'countdown.lineOpen'
  const dates: Partial<Record<JourneyPhase['key'], string>> = {
    application: closes ? t(deadlineKey, { date: closes }) : confirmedSchedule.deadline,
    judging: judging ? t('countdown.judgingLine', { period: judging }) : confirmedSchedule.judging,
  }

  return (
    <>
      <PageHeader trail={[{ href: '/', label: t('nav.home') }, { href: page.href, label: page.label }]} eyebrow={award.name} title={t('journey.title')}>
        <p>{t('journey.intro')}</p>
      </PageHeader>

      {/* The owner's «الدورة التشغيلية ومسار الجائزة»: three stages, then the detailed timeline. */}
      <section className="section section--subtle" aria-labelledby="cycle-title">
        <div className="container">
          <h2 id="cycle-title" className="section__title">
            {t('journey.cycleTitle')}
          </h2>
          <ol className="cycle" aria-label={t('journey.cycleStages')}>
            {cycle.map((stage, index) => (
              <li key={stage.title} className="cycle__stage" data-stage={index + 1}>
                <h3 className="cycle__title">{stage.title}</h3>
                <div className="cycle__card">
                  <span className="cycle__number" aria-hidden="true">
                    {String(index + 1).padStart(2, '0')}
                  </span>
                  <ul className="cycle__points">
                    {stage.points.map((point) => (
                      <li key={point}>{point}</li>
                    ))}
                  </ul>
                </div>
              </li>
            ))}
          </ol>
        </div>
      </section>

      <section className="section" aria-labelledby="timeline-title">
        <div className="container">
          <h2 id="timeline-title" className="visually-hidden">
            {t('journey.phases')}
          </h2>
          <ol className="timeline">
            {journey.map((phase, index) => {
              const status = statusOf(phase.key, now, schedule)
              return (
                <li key={phase.key} className="timeline__phase" data-tone={status?.tone ?? 'neutral'}>
                  <span className="timeline__marker" aria-hidden="true">
                    {index + 1}
                  </span>
                  <div className="timeline__card">
                    <div className="timeline__head">
                      <h3 className="timeline__title">
                        <span className="visually-hidden">{t('journey.phaseN', { n: index + 1 })}</span>
                        {phase.title}
                      </h3>
                      {status && <span className="timeline__status">{t(status.key)}</span>}
                    </div>
                    <p className="timeline__summary">{phase.summary}</p>
                    {dates[phase.key] && <p className="timeline__date">{dates[phase.key]}</p>}
                    <ul className="timeline__points">
                      {phase.points.map((point) => (
                        <li key={point}>{point}</li>
                      ))}
                    </ul>
                  </div>
                </li>
              )
            })}
          </ol>
          <p className="note">{t('journey.note')}</p>
        </div>
      </section>
    </>
  )
}
