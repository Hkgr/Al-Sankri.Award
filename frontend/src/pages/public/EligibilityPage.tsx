import { domains, eligibility, pages } from '../../content/site'
import type { MainDomain } from '../../content/site'
import { Link } from '../../lib/router'
import { usePageMeta } from '../../lib/usePageMeta'
import { PageHeader } from './PageHeader'
import './public.css'

/** The shared conditions, evidence, conflict-of-interest rule and evaluation criteria. */
function SharedRules({ headingLevel }: { headingLevel: 2 | 3 }) {
  const Heading = headingLevel === 2 ? 'h2' : 'h3'
  const Item = headingLevel === 2 ? 'h3' : 'h4'

  return (
    <>
      <section className="rules-block" aria-labelledby="conditions-title">
        <Heading id="conditions-title" className="section__title">
          الشروط المشتركة
        </Heading>
        <ol className="rules-list">
          {eligibility.conditions.map((condition) => (
            <li key={condition.title} className="rules-list__item">
              <Item className="rules-list__title">{condition.title}</Item>
              <p>{condition.text}</p>
            </li>
          ))}
        </ol>
      </section>

      <section className="rules-block split" aria-labelledby="applicants-title">
        <div>
          <Heading id="applicants-title" className="section__title">
            من يحق له التقدم
          </Heading>
          <p className="prose">{eligibility.applicants}</p>
        </div>
        <div className="org-panel org-panel--warning">
          <Heading className="org-panel__title">منع تضارب المصالح</Heading>
          <p>{eligibility.conflict}</p>
        </div>
      </section>

      <section className="rules-block" aria-labelledby="evidence-title">
        <Heading id="evidence-title" className="section__title">
          الوثائق وأدلة الأثر
        </Heading>
        <p className="prose">{eligibility.evidence.intro}</p>
        <ul className="check-list">
          {eligibility.evidence.items.map((item) => (
            <li key={item}>{item}</li>
          ))}
        </ul>
      </section>

      <section className="rules-block" aria-labelledby="criteria-title">
        <Heading id="criteria-title" className="section__title">
          مبادئ التقييم
        </Heading>
        <p className="prose">تُقيَّم الملفات المحالة إلى لجان التحكيم وفق معايير الجودة والحوكمة التالية:</p>
        <ul className="card-grid card-grid--criteria">
          {eligibility.criteria.map((criterion) => (
            <li key={criterion.title} className="info-card info-card--rule">
              <Item className="info-card__title">{criterion.title}</Item>
              <p>{criterion.text}</p>
            </li>
          ))}
        </ul>
        <p className="note">
          يصل كل ملف مرسل إلى إدارة الجائزة أولاً للتدقيق، ثم تحيله إدارة الجائزة إلى لجنة تحكيم محوره الرئيسي، وتتألف كل لجنة من 3 أو 5 محكّمين.
        </p>
      </section>
    </>
  )
}

export function EligibilityPage() {
  const page = pages[3]
  usePageMeta(page.label, page.description)

  return (
    <>
      <PageHeader trail={[{ href: '/', label: 'الرئيسية' }, { href: page.href, label: page.label }]} eyebrow="الدورة الأولى" title="شروط التقدم">
        <p>{eligibility.shared}</p>
      </PageHeader>

      <section className="section" aria-labelledby="domains-title">
        <div className="container">
          <h2 id="domains-title" className="section__title">
            المحاور وفئاتها
          </h2>
          <ul className="domain-links">
            {domains.map((domain) => (
              <li key={domain.slug}>
                <DomainLinkCard domain={domain} />
              </li>
            ))}
          </ul>
        </div>
      </section>

      <div className="section section--subtle">
        <div className="container">
          <SharedRules headingLevel={2} />
        </div>
      </div>
    </>
  )
}

function DomainLinkCard({ domain }: { domain: MainDomain }) {
  return (
    <Link className="domain-link" href={`/eligibility/${domain.slug}`}>
      <img src={domain.symbol} alt="" width={64} height={72} />
      <span className="domain-link__text">
        <span className="domain-link__title">{domain.label}</span>
        <span className="domain-link__subs">{domain.subcategories.map((subcategory) => subcategory.label).join('، ')}</span>
      </span>
    </Link>
  )
}

export function EligibilityDomainPage({ domain }: { domain: MainDomain }) {
  const page = pages[3]
  usePageMeta(`${domain.label} | ${page.label}`, `محور ${domain.label} في جائزة الدكتور عبد القادر سنكري: ${domain.subcategories.map((subcategory) => subcategory.label).join('، ')}، وشروط التقدم المشتركة.`)
  const others = domains.filter((other) => other.slug !== domain.slug)

  return (
    <>
      <PageHeader
        trail={[
          { href: '/', label: 'الرئيسية' },
          { href: page.href, label: page.label },
          { href: `/eligibility/${domain.slug}`, label: domain.label },
        ]}
        eyebrow="محور رئيسي"
        title={domain.label}
      >
        <div className="domain-hero">
          <img className="domain-hero__symbol" src={domain.symbol} alt="" width={112} height={126} />
          <p>{domain.intro}</p>
        </div>
      </PageHeader>

      <section className="section" aria-labelledby="subcategories-title">
        <div className="container">
          <h2 id="subcategories-title" className="section__title">
            فئات المحور
          </h2>
          <p className="prose">
            يُقدَّم الترشح في هذا المحور ضمن فئة واحدة من فئاته، وعدد جوائزه في هذه الدورة: {domain.awards}.
          </p>
          <ul className={`card-grid ${domain.subcategories.length === 3 ? 'card-grid--three' : 'card-grid--two'}`}>
            {domain.subcategories.map((subcategory) => (
              <li key={subcategory.label} className="info-card info-card--subcategory">
                <h3 className="info-card__title">{subcategory.label}</h3>
                <p>{subcategory.scope}</p>
              </li>
            ))}
          </ul>
          <p className="note">{eligibility.shared}</p>
        </div>
      </section>

      <div className="section section--subtle">
        <div className="container">
          <SharedRules headingLevel={2} />
        </div>
      </div>

      <nav className="section" aria-labelledby="other-domains-title">
        <div className="container">
          <h2 id="other-domains-title" className="section__title">
            المحوران الآخران
          </h2>
          <ul className="domain-links domain-links--two">
            {others.map((other) => (
              <li key={other.slug}>
                <DomainLinkCard domain={other} />
              </li>
            ))}
          </ul>
        </div>
      </nav>
    </>
  )
}
