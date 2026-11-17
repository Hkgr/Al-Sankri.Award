import { Fragment } from 'react'
import type { CSSProperties } from 'react'
import { DomainTrophy } from '../../components/DomainTrophy'
import { Photo } from '../../components/Photo'
import { PlatformActions } from '../../components/PlatformActions'
import { pageAt } from '../../content/site'
import { useSite } from '../../content/useSite'
import { useLocale } from '../../i18n/useLocale'
import { Link } from '../../lib/router'
import { phaseAt } from '../../lib/schedule'
import { usePageMeta } from '../../lib/usePageMeta'
import { useSchedule, useServerNow } from '../../lib/useSchedule'
import './home.css'

/** Small gold specks rising behind the hero: a fixed, evenly varied set (no randomness at render). */
const SPECKS: CSSProperties[] = Array.from({ length: 24 }, (_, index) => {
  const step = (index * 37) % 24
  return {
    left: `${((index * 41) % 100) + 0.5}%`,
    '--size': `${2 + (step % 5)}px`,
    '--duration': `${10 + (step % 9)}s`,
    '--delay': `${-((index * 7) % 18)}s`,
    '--drift': `${((index * 13) % 60) - 30}px`,
  } as CSSProperties
})

/** The slogan's two halves, split after the ellipsis, with each word's position for the staggered rise. */
function sloganLines(slogan: string) {
  let offset = 0
  return slogan.split('... ').map((part, index, parts) => {
    const text = index < parts.length - 1 ? `${part}...` : part
    const words = text.split(' ')
    const line = { text, words, offset }
    offset += words.length
    return line
  })
}

export function HomePage() {
  const { about, award, domains, hero, journey, pages, why } = useSite()
  const { t } = useLocale()
  usePageMeta(null, pageAt(pages, '/').description)
  const { schedule, offset } = useSchedule()
  // The phase decides which way into the platform is offered; it changes at the closing instant.
  const now = useServerNow(offset, Boolean(schedule))
  const phase = schedule ? phaseAt(now, schedule) : 'unknown'

  return (
    <>
      {/* The ceremonial hero: the award name and slogan beside the portrait in its gold orbits. */}
      <section className="hero" aria-labelledby="hero-title">
        <div className="hero__specks" aria-hidden="true">
          {SPECKS.map((speck) => (
            <span key={speck.left} style={speck} />
          ))}
        </div>

        <div className="hero__inner container">
          <div className="hero__text">
            {/* The logo stays in the header (owner decision); the hero names the award in text. */}
            <p className="hero__name">
              <span className="hero__rule" aria-hidden="true" />
              {award.name}
            </p>
            <h1 id="hero-title" className="hero__title">
              {/* Break after the ellipsis so each half of the slogan stays whole; the words rise one after another. */}
              {sloganLines(award.slogan).map((line, lineIndex) => (
                <Fragment key={line.text}>
                  {/* A space between the halves, so the heading reads as one sentence. */}
                  {lineIndex > 0 && ' '}
                  <span className="hero__title-line">
                    {line.words.map((word, wordIndex) => (
                      <Fragment key={`${word}-${wordIndex}`}>
                        {/* The space stays outside the masked word, where it cannot collapse. */}
                        {wordIndex > 0 && ' '}
                        <span className="hero__word">
                          <span style={{ '--i': line.offset + wordIndex } as CSSProperties}>{word}</span>
                        </span>
                      </Fragment>
                    ))}
                  </span>
                </Fragment>
              ))}
            </h1>
            <p className="hero__lead">{hero.lead}</p>
            <PlatformActions phase={phase} secondary={{ href: '/eligibility', label: t('actions.readEligibility') }} />
          </div>

          <figure className="hero__portrait">
            <svg className="hero__orbits" viewBox="0 0 100 100" aria-hidden="true" focusable="false">
              <circle className="hero__orbit hero__orbit--outer" cx="50" cy="50" r="49.5" />
              <g className="hero__spin hero__spin--reverse">
                <circle className="hero__orbit hero__orbit--dashed" cx="50" cy="50" r="46" />
              </g>
              <circle className="hero__orbit hero__orbit--gold" cx="50" cy="50" r="42.6" pathLength={1} transform="rotate(-90 50 50)" />
              <g className="hero__spin">
                <circle className="hero__planet" cx="50" cy="0.5" r="1.1" />
                <circle className="hero__planet hero__planet--small" cx="96" cy="50" r="0.7" />
              </g>
            </svg>
            <Photo className="hero__photo" {...hero.portrait} alt="" sizes="(min-width: 64rem) 27rem, 16rem" loading="eager" fetchPriority="high" />
            <figcaption className="hero__caption">{hero.portrait.caption}</figcaption>
          </figure>
        </div>
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

      {/* The owner's «لماذا الجائزة؟»: the title on deep green beside four numbered reasons. */}
      <section className="why" aria-labelledby="why-title">
        <div className="container why__inner">
          <div className="why__panel">
            <h2 id="why-title" className="why__title">
              {t('home.whyTitle')}
            </h2>
          </div>
          <ol className="why__list">
            {why.map((reason, index) => (
              <li key={reason.title} className="why__item">
                <span className="why__number" aria-hidden="true">
                  {String(index + 1).padStart(2, '0')}
                </span>
                <div>
                  <h3 className="why__item-title">{reason.title}</h3>
                  <p>{reason.text}</p>
                </div>
              </li>
            ))}
          </ol>
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
