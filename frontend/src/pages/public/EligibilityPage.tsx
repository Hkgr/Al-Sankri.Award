import { RichText } from '../../components/OrgLink'
import type { MainDomain } from '../../content/site'
import { useSite } from '../../content/useSite'
import { useLocale } from '../../i18n/useLocale'
import { Link } from '../../lib/router'
import { usePageMeta } from '../../lib/usePageMeta'
import { PageHeader } from './PageHeader'
import './public.css'

/** The shared conditions, evidence, conflict-of-interest rule and evaluation criteria. */
function SharedRules({ headingLevel }: { headingLevel: 2 | 3 }) {
  const { eligibility } = useSite()
  const { t } = useLocale()
  const Heading = headingLevel === 2 ? 'h2' : 'h3'
  const Item = headingLevel === 2 ? 'h3' : 'h4'

  return (
    <>
      <section className="rules-block" aria-labelledby="conditions-title">
        <Heading id="conditions-title" className="section__title">
          {t('eligibility.conditions')}
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
            {t('eligibility.who')}
          </Heading>
          <p className="prose">{eligibility.applicants}</p>
        </div>
        <div className="org-panel org-panel--warning">
          <Heading className="org-panel__title">{t('eligibility.conflict')}</Heading>
          <p>
            <RichText value={eligibility.conflict} />
          </p>
        </div>
      </section>

      <section className="rules-block" aria-labelledby="evidence-title">
        <Heading id="evidence-title" className="section__title">
          {t('eligibility.evidence')}
        </Heading>
        <p className="prose">{eligibility.evidence.intro}</p>
        <ul className="check-list">
          {eligibility.evidence.items.map((item) => (
            <li key={item}>{item}</li>
          ))}
        </ul>
      </section>

      <p className="note">{t('eligibility.flowNote')}</p>
    </>
  )
}

export function EligibilityPage() {
  const { domains, eligibility, pages } = useSite()
  const { t } = useLocale()
  const page = pages[3]
  usePageMeta(page.label, page.description)

  return (
    <>
      <PageHeader trail={[{ href: '/', label: t('nav.home') }, { href: page.href, label: page.label }]} eyebrow={t('eligibility.eyebrow')} title={t('eligibility.title')}>
        <p>{eligibility.shared}</p>
      </PageHeader>

      <section className="section" aria-labelledby="domains-title">
        <div className="container">
          <h2 id="domains-title" className="section__title">
            {t('eligibility.domains')}
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
          {domains.map((domain) => (
            <section className="rules-block" key={domain.slug}>
              <h2 className="section__title">{domain.label}</h2>
              <CategoryConditions domain={domain} />
            </section>
          ))}
        </div>
      </div>
    </>
  )
}

function DomainLinkCard({ domain }: { domain: MainDomain }) {
  const { t } = useLocale()

  return (
    <Link className="domain-link" href={`/eligibility/${domain.slug}`}>
      <img src={domain.symbol} alt="" width={64} height={72} />
      <span className="domain-link__text">
        <span className="domain-link__title">{domain.label}</span>
        <span className="domain-link__subs">{domain.subcategories.map((subcategory) => subcategory.label).join(t('eligibility.listSeparator'))}</span>
      </span>
    </Link>
  )
}

function CategoryConditions({ domain }: { domain: MainDomain }) {
  return (
    <ul className="card-grid card-grid--two">
      {domain.subcategories.map((subcategory) => (
        <li key={subcategory.code} className="info-card info-card--subcategory">
          <h3 className="info-card__title">{subcategory.label}</h3>
          <ol className="check-list">
            {subcategory.requirements.map((condition) => <li key={condition}>{condition}</li>)}
          </ol>
        </li>
      ))}
    </ul>
  )
}

export function EligibilityDomainPage({ domain: requested }: { domain: MainDomain }) {
  const { domains, eligibility, pages } = useSite()
  const { t } = useLocale()
  const domain = domains.find((item) => item.slug === requested.slug) ?? requested
  const page = pages[3]
  usePageMeta(
    `${domain.label} | ${page.label}`,
    t('eligibility.domainMeta', { domain: domain.label, subs: domain.subcategories.map((subcategory) => subcategory.label).join(t('eligibility.listSeparator')) }),
  )
  const others = domains.filter((other) => other.slug !== domain.slug)

  return (
    <>
      <PageHeader
        trail={[
          { href: '/', label: t('nav.home') },
          { href: page.href, label: page.label },
          { href: `/eligibility/${domain.slug}`, label: domain.label },
        ]}
        eyebrow={t('eligibility.domainEyebrow')}
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
            {t('eligibility.subcategories')}
          </h2>
          <CategoryConditions domain={domain} />
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
            {t('eligibility.others')}
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
