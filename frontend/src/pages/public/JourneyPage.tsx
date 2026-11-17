import { pageAt } from '../../content/site'
import { useSite } from '../../content/useSite'
import { useLocale } from '../../i18n/useLocale'
import { usePageMeta } from '../../lib/usePageMeta'
import { PageHeader } from './PageHeader'
import './public.css'

export function JourneyPage() {
  const { award, journey, pages } = useSite()
  const { t } = useLocale()
  const page = pageAt(pages, '/journey')
  usePageMeta(page.label, page.description)

  return (
    <>
      <PageHeader trail={[{ href: '/', label: t('nav.home') }, { href: page.href, label: page.label }]} eyebrow={award.name} title={t('journey.title')}>
        <p>{t('journey.intro')}</p>
      </PageHeader>

      <section className="section" aria-labelledby="timeline-title">
        <div className="container">
          <h2 id="timeline-title" className="visually-hidden">
            {t('journey.phases')}
          </h2>
          <ol className="timeline">
            {journey.map((phase, index) => (
              <li key={phase.key} className="timeline__phase">
                <span className="timeline__marker" aria-hidden="true">{index + 1}</span>
                <div className="timeline__card">
                  <h3 className="timeline__title">
                    <span className="visually-hidden">{t('journey.phaseN', { n: index + 1 })}</span>
                    {phase.title}
                  </h3>
                  <p className="timeline__summary">{phase.summary}</p>
                  <ul className="timeline__points">
                    {phase.points.map((point) => <li key={point}>{point}</li>)}
                  </ul>
                </div>
              </li>
            ))}
          </ol>
        </div>
      </section>
    </>
  )
}
