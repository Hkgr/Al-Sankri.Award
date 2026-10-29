import { RichText } from '../../components/OrgLink'
import { Photo } from '../../components/Photo'
import { about, award, hero, legacy, pages } from '../../content/site'
import { usePageMeta } from '../../lib/usePageMeta'
import { PageHeader } from './PageHeader'
import './public.css'

export function AboutPage() {
  const page = pages[1]
  usePageMeta(page.label, page.description)

  return (
    <>
      <PageHeader trail={[{ href: '/', label: 'الرئيسية' }, { href: page.href, label: page.label }]} eyebrow={award.slogan} title="عن الجائزة">
        <p>{about.philosophy}</p>
      </PageHeader>

      <section className="section" aria-labelledby="aims-title">
        <div className="container">
          <p className="pull-quote">{about.statement}</p>
          <h2 id="aims-title" className="section__title">
            غاية الجائزة
          </h2>
          <ol className="card-grid card-grid--three">
            {about.aims.map((aim) => (
              <li key={aim.title} className="info-card">
                <h3 className="info-card__title">{aim.title}</h3>
                <p>{aim.text}</p>
              </li>
            ))}
          </ol>
        </div>
      </section>

      <section className="section section--subtle" aria-labelledby="values-title">
        <div className="container">
          <h2 id="values-title" className="section__title">
            قيمنا
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
              التركيز على الأثر الموثق
            </h2>
            <p className="prose">{about.impact}</p>
          </div>
          <div className="org-panel">
            <h2 className="org-panel__title">الجهة المنفذة</h2>
            <p>
              <RichText value={about.organization} />
            </p>
          </div>
        </div>
      </section>

      <section className="section legacy" aria-labelledby="legacy-title">
        <div className="container legacy__inner">
          <div className="legacy__text">
            <p className="eyebrow">سيرة العطاء</p>
            <h2 id="legacy-title" className="section__title">
              الدكتور عبد القادر سنكري
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
