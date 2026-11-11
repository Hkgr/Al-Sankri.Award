import { BrandLogo } from '../../components/BrandLogo'
import { Countdown } from '../../components/Countdown'
import { DomainTrophy } from '../../components/DomainTrophy'
import { Photo } from '../../components/Photo'
import { PlatformActions } from '../../components/PlatformActions'
import { useSite } from '../../content/useSite'
import { useLocale } from '../../i18n/useLocale'
import { Link } from '../../lib/router'
import { phaseAt } from '../../lib/schedule'
import { usePageMeta } from '../../lib/usePageMeta'
import { useSchedule, useServerNow } from '../../lib/useSchedule'
import './home.css'

export function HomePage() {
  const { about, award, domains, hero, journey, pages } = useSite()
  const { t } = useLocale()
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
            <PlatformActions phase={phase} secondary={{ href: '/eligibility', label: t('actions.readEligibility') }} />
          </div>

          {/* The official logo leads the deep-green card; the countdown stays beneath it. */}
          <div className="hero__stage">
            <BrandLogo surface="dark" className="hero__logo" alt={award.name} sizes="(min-width: 64rem) 352px, (min-width: 40rem) 320px, 72vw" />
            <Countdown schedule={schedule} offset={offset} />
          </div>
        </div>

        {/* The faded portrait beside the content, never behind the text or the countdown; its caption names him. */}
        <figure className="hero__portrait">
          <Photo className="hero__portrait-photo" {...hero.portrait} alt="" sizes="(min-width: 64rem) 34rem, (min-width: 40rem) 22rem, 56vw" loading="eager" />
          <figcaption className="hero__portrait-caption">{hero.portrait.caption}</figcaption>
        </figure>
      </section>

      <section className="section intro" aria-labelledby="intro-title">
        <div className="container intro__inner">
          <div>
            <p className="eyebrow">{t('home.aboutEyebrow')}</p>
            <h2 id="intro-title" className="section__title">
              {t('home.aboutTitle')}
            </h2>
          </div>
          <div className="intro__body">
            <p className="intro__statement">{about.statement}</p>
            <p>{about.philosophy}</p>
            <Link className="text-link" href="/about">
              {t('home.aboutMore')}
            </Link>
          </div>
        </div>
      </section>

      <section className="section domains-preview" aria-labelledby="domains-title">
        <div className="container">
          <div className="section__head">
            <p className="eyebrow">{t('home.domainsEyebrow')}</p>
            <h2 id="domains-title" className="section__title">
              {t('home.domainsTitle')}
            </h2>
          </div>
          {/* One full-width row per domain, stacked at every width; the trophy sits beside the text from 48rem. */}
          <ul className="domain-rows">
            {domains.map((domain) => (
              <li key={domain.slug} className="domain-row">
                <div className="domain-row__body">
                  <h3 className="domain-row__title">{domain.label}</h3>
                  <p className="domain-row__intro">{domain.intro}</p>
                  <ul className="domain-row__subcategories" aria-label={t('home.domainSubs', { domain: domain.label })}>
                    {domain.subcategories.map((subcategory) => (
                      <li key={subcategory.code}>
                        <Link href={`/eligibility/${domain.slug}#category-${subcategory.code}`}>{subcategory.label}</Link>
                      </li>
                    ))}
                  </ul>
                  <Link className="domain-row__link" href={`/eligibility/${domain.slug}`}>
                    {t('home.domainLink')}
                    <span className="visually-hidden">: {domain.label}</span>
                  </Link>
                </div>
                {/* The visible heading names the artwork, so it is decorative. */}
                <DomainTrophy domain={domain.slug} className="domain-row__trophy" sizes="(min-width: 80rem) 14rem, 12rem" />
              </li>
            ))}
          </ul>
        </div>
      </section>

      <section className="section journey-preview" aria-labelledby="journey-title">
        <div className="container">
          <div className="section__head">
            <p className="eyebrow">{t('home.journeyEyebrow')}</p>
            <h2 id="journey-title" className="section__title">
              {t('home.journeyTitle')}
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
            {t('home.journeyMore')}
          </Link>
        </div>
      </section>

      <section className="platform-band" aria-labelledby="platform-title">
        <div className="container platform-band__inner">
          <div>
            <h2 id="platform-title" className="platform-band__title">
              {t('home.platformTitle')}
            </h2>
            <p className="platform-band__text">{t('home.platformText')}</p>
          </div>
          <PlatformActions phase={phase} secondary={{ href: '/eligibility', label: t('actions.eligibility') }} />
        </div>
      </section>
    </>
  )
}
