import { RichText } from '../../components/OrgLink'
import { Photo } from '../../components/Photo'
import { pageAt } from '../../content/site'
import { useSite } from '../../content/useSite'
import { useLocale } from '../../i18n/useLocale'
import { usePageMeta } from '../../lib/usePageMeta'
import { PageHeader } from './PageHeader'
import './public.css'

export function AboutPage() {
  const { about, award, hero, legacy, pages, purpose } = useSite()
  const { t } = useLocale()
  const page = pageAt(pages, '/about')
  usePageMeta(page.label, page.description)

  return (
    <>
      <PageHeader trail={[{ href: '/', label: t('nav.home') }, { href: page.href, label: page.label }]} eyebrow={award.slogan} title={t('about.title')}>
        <p>{about.philosophy}</p>
      </PageHeader>

      {/* The owner's «الغاية والأهداف الاستراتيجية». */}
      <section className="section" aria-labelledby="purpose-title">
        <div className="container">
          <p className="pull-quote">{about.statement}</p>
          <h2 id="purpose-title" className="section__title">
            {t('about.purposeTitle')}
          </h2>
          <figure className="purpose-banner">
            <figcaption className="purpose-banner__label">{t('about.grand')}</figcaption>
            <blockquote className="purpose-banner__quote">
              <p>{purpose.grand}</p>
            </blockquote>
          </figure>
          <h3 className="objectives__title">{t('about.objectives')}</h3>
          <ol className="objectives">
            {purpose.objectives.map((objective, index) => (
              <li key={objective} className="objectives__item">
                <span className="objectives__number" aria-hidden="true">
                  {String(index + 1).padStart(2, '0')}
                </span>
                <p>{objective}</p>
              </li>
            ))}
          </ol>
        </div>
      </section>

      <section className="section section--subtle" aria-labelledby="values-title">
        <div className="container">
          <h2 id="values-title" className="section__title">
            {t('about.values')}
          </h2>
          <ul className="card-grid card-grid--four">
            {about.values.map((value) => (
              <li key={value.title} className="info-card info-card--rule">
                <h3 className="info-card__title">{value.title}</h3>
                <p>{value.text}</p>
              </li>
            ))}
          </ul>
        </div>
      </section>

      <section className="section" aria-labelledby="impact-title">
        <div className="container split">
          <div>
            <h2 id="impact-title" className="section__title">
              {t('about.impact')}
            </h2>
            <p className="prose">{about.impact}</p>
          </div>
          <div className="org-panel">
            <h2 className="org-panel__title">{t('about.organization')}</h2>
            <p>
              <RichText value={about.organization} />
            </p>
          </div>
        </div>
      </section>

      <section className="section legacy" aria-labelledby="legacy-title">
        <div className="container legacy__inner">
          <div className="legacy__text">
            <p className="eyebrow">{t('about.legacyEyebrow')}</p>
            <h2 id="legacy-title" className="section__title">
              {t('about.legacyTitle')}
            </h2>
            {legacy.paragraphs.map((paragraph, index) => (
              <p key={index} className="prose">
                <RichText value={paragraph} />
              </p>
            ))}
            <p className="legacy__bridge">{legacy.bridge}</p>
          </div>
          <div className="legacy__photos">
            <figure className="legacy__portrait">
              <Photo {...hero.portrait} sizes="(min-width: 64rem) 16rem, 50vw" />
              <figcaption>{hero.portrait.caption}</figcaption>
            </figure>
            <figure className="legacy__figure">
              <Photo {...legacy.photo} sizes="(min-width: 64rem) 40rem, 100vw" />
              <figcaption>{legacy.photo.caption}</figcaption>
            </figure>
          </div>
        </div>
      </section>
    </>
  )
}
