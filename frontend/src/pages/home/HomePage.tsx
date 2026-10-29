import { Countdown } from '../../components/Countdown'
import { Photo } from '../../components/Photo'
import { PlatformActions } from '../../components/PlatformActions'
import { about, award, domains, hero, journey, pages } from '../../content/site'
import { Link } from '../../lib/router'
import { phaseAt } from '../../lib/schedule'
import { usePageMeta } from '../../lib/usePageMeta'
import { useSchedule, useServerNow } from '../../lib/useSchedule'
import './home.css'

export function HomePage() {
  usePageMeta(null, pages[0].description)
  const { schedule, offset } = useSchedule()
  // The phase decides which way into the platform is offered; it changes at the closing instant.
  const now = useServerNow(offset, Boolean(schedule))
  const phase = schedule ? phaseAt(now, schedule) : 'unknown'

  return (
    <>
      <section className="hero" aria-labelledby="hero-title">
        <div className="hero__inner container">
          <div className="hero__text">
            <p className="hero__name">{award.name}</p>
            <h1 id="hero-title" className="hero__title">
              {/* Break after the ellipsis so each half of the slogan stays whole. */}
              {award.slogan.split('... ').map((line, index, lines) => (
                <span key={line} className="hero__title-line">
                  {index < lines.length - 1 ? `${line}...` : line}
                </span>
              ))}
            </h1>
            <p className="hero__lead">{hero.lead}</p>
            <PlatformActions phase={phase} secondary={{ href: '/eligibility', label: 'اقرأ شروط التقدم' }} />
          </div>

          <div className="hero__stage">
            <figure className="hero__figure">
              <Photo className="hero__photo" {...hero.portrait} sizes="(min-width: 64rem) 25rem, (min-width: 40rem) 22rem, 82vw" loading="eager" fetchPriority="high" />
              <figcaption className="hero__caption">{hero.portrait.caption}</figcaption>
            </figure>
            <Countdown schedule={schedule} offset={offset} />
          </div>
        </div>
      </section>

      <section className="section intro" aria-labelledby="intro-title">
        <div className="container intro__inner">
          <div>
            <p className="eyebrow">عن الجائزة</p>
            <h2 id="intro-title" className="section__title">
              أثرٌ يُرى، وأصحابه يُكرَّمون
            </h2>
          </div>
          <div className="intro__body">
            <p className="intro__statement">{about.statement}</p>
            <p>{about.philosophy}</p>
            <Link className="text-link" href="/about">
              المزيد عن الجائزة
            </Link>
          </div>
        </div>
      </section>

      <section className="section domains-preview" aria-labelledby="domains-title">
        <div className="container">
          <div className="section__head">
            <p className="eyebrow">محاور الجائزة</p>
            <h2 id="domains-title" className="section__title">
              ثلاثة محاور رئيسية، وسبع فئات
            </h2>
          </div>
          <ul className="domain-cards">
            {domains.map((domain) => (
              <li key={domain.slug} className="domain-card">
                {/* The visible label names the artwork, so it is decorative. */}
                <img className="domain-card__symbol" src={domain.symbol} alt="" width={88} height={99} />
                <h3 className="domain-card__title">{domain.label}</h3>
                <p className="domain-card__intro">{domain.intro}</p>
                <ul className="domain-card__subcategories" aria-label={`فئات محور ${domain.label}`}>
                  {domain.subcategories.map((subcategory) => (
                    <li key={subcategory.label}>{subcategory.label}</li>
                  ))}
                </ul>
                <Link className="domain-card__link" href={`/eligibility/${domain.slug}`}>
                  شروط المحور وفئاته<span className="visually-hidden">: {domain.label}</span>
                </Link>
              </li>
            ))}
          </ul>
        </div>
      </section>

      <section className="section journey-preview" aria-labelledby="journey-title">
        <div className="container">
          <div className="section__head">
            <p className="eyebrow">مسيرة الجائزة</p>
            <h2 id="journey-title" className="section__title">
              من الإعداد إلى أسبوع الجائزة
            </h2>
          </div>
          <ol className="journey-strip">
            {journey.map((phaseItem, index) => (
              <li key={phaseItem.key} className="journey-strip__step">
                <span className="journey-strip__number" aria-hidden="true">
                  {index + 1}
                </span>
                <h3 className="journey-strip__title">{phaseItem.title}</h3>
                <p>{phaseItem.summary}</p>
              </li>
            ))}
          </ol>
          <Link className="text-link" href="/journey">
            تفاصيل مسيرة الجائزة ومواعيدها
          </Link>
        </div>
      </section>

      <section className="platform-band" aria-labelledby="platform-title">
        <div className="container platform-band__inner">
          <div>
            <h2 id="platform-title" className="platform-band__title">
              منصة الترشح الإلكترونية
            </h2>
            <p className="platform-band__text">
              يُقدَّم ملف الترشح عبر المنصة: البيانات الأساسية، ثم فئة الترشح وبيانات العمل، ثم ملفات العمل وأدلة أثره. يصل كل ملف مرسل إلى إدارة الجائزة أولاً للتدقيق
              قبل إحالته إلى لجنة التحكيم المختصة.
            </p>
          </div>
          <PlatformActions phase={phase} secondary={{ href: '/eligibility', label: 'شروط التقدم' }} />
        </div>
      </section>
    </>
  )
}
