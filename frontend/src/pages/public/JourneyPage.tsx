import { award, confirmedSchedule, journey, pages } from '../../content/site'
import type { JourneyPhase } from '../../content/site'
import { deadlineText, periodText, phaseAt } from '../../lib/schedule'
import type { PublicSchedule } from '../../lib/schedule'
import { usePageMeta } from '../../lib/usePageMeta'
import { useSchedule, useServerNow } from '../../lib/useSchedule'
import { PageHeader } from './PageHeader'
import './public.css'

type Status = { label: string; tone: 'planned' | 'current' | 'done' | 'neutral' }

/**
 * A phase's status, from the published schedule only: the application and
 * judging periods are «جارية» or «انتهت» by their dates; the other phases
 * carry no completion claim (planned phases say so).
 */
function statusOf(key: JourneyPhase['key'], now: number, schedule: PublicSchedule | null | undefined): Status | null {
  const phase = schedule ? phaseAt(now, schedule) : 'unknown'
  const judgingStarts = schedule?.judging?.opens_at ? Date.parse(schedule.judging.opens_at) : null

  switch (key) {
    case 'application':
      if (phase === 'open') return { label: 'جارية الآن', tone: 'current' }
      if (phase === 'upcoming') return { label: 'مقبلة', tone: 'planned' }
      if (phase === 'judging' || phase === 'judged') return { label: 'انتهت', tone: 'done' }
      return null
    case 'judging':
      if (phase === 'judged') return { label: 'انتهت', tone: 'done' }
      if (phase === 'judging' && (judgingStarts === null || now >= judgingStarts)) return { label: 'جارية الآن', tone: 'current' }
      if (phase === 'open' || phase === 'upcoming' || phase === 'judging') return { label: 'مقبلة', tone: 'planned' }
      return null
    case 'recognition':
      return { label: 'مخطط لها', tone: 'planned' }
    case 'screening':
      return { label: 'مع وصول الملفات المرسلة', tone: 'neutral' }
    default:
      return null
  }
}

export function JourneyPage() {
  const page = pages[2]
  usePageMeta(page.label, page.description)
  const { schedule, offset } = useSchedule()
  const now = useServerNow(offset, Boolean(schedule))

  const closes = schedule?.application?.closes_at ? deadlineText(schedule.application.closes_at) : null
  const judging = schedule?.judging ? periodText(schedule.judging.opens_at, schedule.judging.closes_at) : null
  const dates: Partial<Record<JourneyPhase['key'], string>> = {
    application: closes ? `يغلق باب الترشح ${closes} — بتوقيت دمشق` : confirmedSchedule.deadline,
    judging: judging ? `فترة التحكيم: ${judging}` : confirmedSchedule.judging,
  }

  return (
    <>
      <PageHeader trail={[{ href: '/', label: 'الرئيسية' }, { href: page.href, label: page.label }]} eyebrow={award.name} title="مسيرة الجائزة">
        <p>
          تمضي الدورة الأولى للجائزة في مراحل متتابعة تضمن النزاهة والحوكمة: من الإعداد، إلى استقبال الترشيحات وتدقيقها، فالتحكيم المستقل، وصولاً إلى أسبوع الجائزة
          المخطط له.
        </p>
      </PageHeader>

      <section className="section" aria-labelledby="timeline-title">
        <div className="container">
          <h2 id="timeline-title" className="visually-hidden">
            مراحل الدورة الأولى
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
                        <span className="visually-hidden">المرحلة {index + 1}: </span>
                        {phase.title}
                      </h3>
                      {status && <span className="timeline__status">{status.label}</span>}
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
          <p className="note">
            هذه الدورة الأولى للجائزة. يُعلن موعد أسبوع الجائزة وفعالياته لاحقاً، وتعلن الجائزة نتائجها رسمياً خلاله.
          </p>
        </div>
      </section>
    </>
  )
}
