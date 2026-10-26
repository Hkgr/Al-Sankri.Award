import { Photo } from '../../components/Photo'
import {
  award,
  closing,
  domains,
  hero,
  journey,
  legacy,
  photoCredit,
  purpose,
  quality,
} from '../../content/home'
import type { Domain } from '../../content/home'
import './home.css'

export function HomePage() {
  return (
    <>
      <HeroSection />
      <PurposeSection />
      <LegacySection />
      <DomainsSection />
      <JourneySection />
      <ClosingSection />
    </>
  )
}

function PhotoCredit({ href }: { href: string }) {
  return (
    <span className="photo-credit">
      الصورة:{' '}
      <a href={href} hrefLang="en">
        منظمة{' '}
        <bdi lang="en" dir="ltr">
          HAND
        </bdi>
      </a>
    </span>
  )
}

function HeroSection() {
  return (
    <section className="hero" aria-labelledby="hero-title">
      <div className="hero__inner container">
        <div className="hero__text">
          <p className="hero__name">{award.name}</p>
          <h1 id="hero-title" className="hero__title">
            {/* Break after the ellipsis so each half of the slogan stays whole. */}
            {award.slogan.split('... ').map((line, index, lines) => (
              <span key={line} className="hero__title-line">
                {index < lines.length - 1 ? `${line}... ` : line}
              </span>
            ))}
          </h1>
          <p className="hero__lead">{hero.lead}</p>
          <div className="hero__actions">
            <a className="button button--primary" href="#domains">
              اكتشف محاور الجائزة
            </a>
            <a className="button button--secondary" href="#about">
              تعرّف على الجائزة
            </a>
          </div>
        </div>

        <figure className="hero__figure">
          <Photo
            className="hero__photo"
            {...hero.portrait}
            sizes="(min-width: 64rem) 34rem, (min-width: 48rem) 40vw, 100vw"
            loading="eager"
            fetchPriority="high"
          />
          <figcaption className="hero__caption">
            <span className="hero__caption-name">{hero.portrait.caption}</span>
            <PhotoCredit href={photoCredit.aboutUrl} />
          </figcaption>
        </figure>
      </div>
    </section>
  )
}

function PurposeSection() {
  return (
    <section id="about" className="section purpose" aria-labelledby="about-title">
      <div className="container">
        <div className="purpose__head">
          <p className="eyebrow">عن الجائزة</p>
          <h2 id="about-title" className="section__title">
            لماذا هذه الجائزة؟
          </h2>
        </div>
        <p className="purpose__statement">{purpose.statement}</p>
        <ol className="purpose__aims">
          {purpose.aims.map((aim) => (
            <li key={aim.title} className="purpose__aim">
              <h3 className="purpose__aim-title">{aim.title}</h3>
              <p>{aim.text}</p>
            </li>
          ))}
        </ol>
      </div>
    </section>
  )
}

function LegacySection() {
  return (
    <section id="legacy" className="section legacy" aria-labelledby="legacy-title">
      <div className="legacy__inner container">
        <div className="legacy__text">
          <p className="eyebrow">سيرة العطاء</p>
          <h2 id="legacy-title" className="section__title">
            الدكتور عبد القادر سنكري
          </h2>
          {legacy.paragraphs.map((paragraph) => (
            <p key={paragraph.slice(0, 24)} className="legacy__paragraph">
              {paragraph}
            </p>
          ))}
          <p className="legacy__bridge">{legacy.bridge}</p>
        </div>

        <figure className="legacy__figure">
          <Photo
            className="legacy__photo"
            {...legacy.photo}
            sizes="(min-width: 64rem) 44rem, 100vw"
          />
          <figcaption className="legacy__caption">
            {legacy.photo.caption} <PhotoCredit href={photoCredit.visitUrl} />
          </figcaption>
        </figure>
      </div>
    </section>
  )
}

function DomainItem({ domain, role }: { domain: Domain; role?: string }) {
  return (
    <>
      {/* The visible label names the symbol, so the image is decorative. */}
      <img className="domain__symbol" src={domain.symbol} alt="" width={64} height={72} />
      <h3 className="domain__label">{domain.label}</h3>
      {role && <p className="domain__role">{role}</p>}
      <p className="domain__text">{domain.text}</p>
      <details className="domain__meaning">
        <summary>معنى الرمز</summary>
        <p>{domain.symbolMeaning}</p>
      </details>
    </>
  )
}

function DomainsSection() {
  return (
    <section id="domains" className="section domains" aria-labelledby="domains-title">
      <div className="container">
        <div className="domains__head">
          <p className="eyebrow">محاور الجائزة</p>
          <h2 id="domains-title" className="section__title">
            المحاور الستة
          </h2>
          <p className="section__intro">
            يقوم نظام الجائزة على رمز موحّد يتغيّر عنصره الداخلي بما يناسب كل محور، وتأتي
            الجودة منهجاً أساسياً متقاطعاً في كافة المحاور.
          </p>
        </div>

        <ul className="domains__list">
          {domains.map((domain) => (
            <li key={domain.key} className="domain">
              <DomainItem domain={domain} />
            </li>
          ))}
        </ul>

        <div className="domain domain--quality">
          <DomainItem domain={quality} role={quality.role} />
        </div>
      </div>
    </section>
  )
}

function JourneySection() {
  return (
    <section id="journey" className="section journey" aria-labelledby="journey-title">
      <div className="container">
        <div className="journey__head">
          <p className="eyebrow">مسار الجائزة</p>
          <h2 id="journey-title" className="section__title">
            من التمهيد إلى أسبوع الجائزة
          </h2>
          <p className="section__intro">{journey.intro}</p>
        </div>
        <ol className="journey__stages">
          {journey.stages.map((stage, index) => (
            <li key={stage.title} className="stage">
              <span className="stage__number" aria-hidden="true">
                {index + 1}
              </span>
              <h3 className="stage__title">
                <span className="visually-hidden">المرحلة {index + 1}: </span>
                {stage.title}
              </h3>
              <p className="stage__summary">{stage.summary}</p>
              <ul className="stage__points">
                {stage.points.map((point) => (
                  <li key={point}>{point}</li>
                ))}
              </ul>
            </li>
          ))}
        </ol>
      </div>
    </section>
  )
}

function ClosingSection() {
  return (
    <section className="closing" aria-labelledby="closing-title">
      <div className="closing__inner container">
        <h2 id="closing-title" className="closing__label">
          ختاماً
        </h2>
        <p className="closing__statement">{closing.statement}</p>
        <p className="closing__text">{closing.text}</p>
      </div>
    </section>
  )
}
