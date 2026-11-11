import type { ReactNode } from 'react'
import { RichText } from '../../components/OrgLink'
import { pageAt } from '../../content/site'
import type { GovernanceStation } from '../../content/site'
import { useSite } from '../../content/useSite'
import { useLocale } from '../../i18n/useLocale'
import { Link } from '../../lib/router'
import { usePageMeta } from '../../lib/usePageMeta'
import { PageHeader } from './PageHeader'
import './public.css'

/** One plain line icon per station (decorative; the station's title names it). */
const ICONS: Record<GovernanceStation['key'], ReactNode> = {
  eligibility: (
    <>
      <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h4" />
      <path d="M14 3v4a1 1 0 0 0 1 1h4v3" />
      <circle cx="16.5" cy="16.5" r="3" />
      <path d="m18.7 18.7 2.3 2.3" />
    </>
  ),
  committees: (
    <>
      <circle cx="9" cy="8" r="3.2" />
      <path d="M3.5 20c0-3.3 2.5-5.5 5.5-5.5s5.5 2.2 5.5 5.5" />
      <path d="M15.5 5.2a3.2 3.2 0 0 1 0 5.6" />
      <path d="M17 14.8c2 .6 3.5 2.5 3.5 5.2" />
    </>
  ),
  field: (
    <>
      <path d="M12 21s-6.5-5.6-6.5-11a6.5 6.5 0 0 1 13 0c0 5.4-6.5 11-6.5 11Z" />
      <circle cx="12" cy="10" r="2.4" />
    </>
  ),
  board: (
    <>
      <path d="M3 9.5 12 4l9 5.5" />
      <path d="M4.5 10h15" />
      <path d="M6.5 10v7M10 10v7M14 10v7M17.5 10v7" />
      <path d="M4 20h16" />
    </>
  ),
}

/**
 * «منظومة الحوكمة» (owner's presentation): the four stations every application
 * passes through, in order, ending with the Board of Trustees' approval; then
 * the conflict-of-interest rule from the published conditions.
 */
export function GovernancePage() {
  const { eligibility, governance, pages } = useSite()
  const { t } = useLocale()
  const page = pageAt(pages, '/governance')
  usePageMeta(page.label, page.description)

  return (
    <>
      <PageHeader trail={[{ href: '/', label: t('nav.home') }, { href: page.href, label: page.label }]} eyebrow={t('governance.eyebrow')} title={t('governance.title')}>
        <p>{t('governance.intro')}</p>
      </PageHeader>

      <section className="governance" aria-labelledby="governance-flow-title">
        <div className="container">
          <h2 id="governance-flow-title" className="visually-hidden">
            {t('governance.flow')}
          </h2>
          <ol className="governance__flow">
            {governance.map((station, index) => (
              <li key={station.key} className={`governance__station${index === governance.length - 1 ? ' governance__station--final' : ''}`}>
                <span className="governance__icon" aria-hidden="true">
                  <svg viewBox="0 0 24 24" focusable="false">
                    {ICONS[station.key]}
                  </svg>
                </span>
                <span className="governance__number" aria-hidden="true">
                  {String(index + 1).padStart(2, '0')}
                </span>
                <h3 className="governance__title">
                  <span className="visually-hidden">{t('governance.stepN', { n: index + 1 })}</span>
                  {station.title}
                </h3>
                <p className="governance__text">{station.text}</p>
              </li>
            ))}
          </ol>
        </div>
      </section>

      <section className="section" aria-labelledby="conflict-title">
        <div className="container split">
          <div className="org-panel org-panel--warning">
            <h2 id="conflict-title" className="org-panel__title">
              {t('governance.conflict')}
            </h2>
            <p>
              <RichText value={eligibility.conflict} />
            </p>
          </div>
          <ul className="governance__links">
            <li>
              <Link className="text-link" href="/journey">
                {t('governance.journeyLink')}
              </Link>
            </li>
            <li>
              <Link className="text-link" href="/eligibility">
                {t('governance.eligibilityLink')}
              </Link>
            </li>
          </ul>
        </div>
      </section>
    </>
  )
}
