import { BrandLogo } from '../BrandLogo'
import { useSite } from '../../content/useSite'
import { useLocale } from '../../i18n/useLocale'
import { Link } from '../../lib/router'
import { PartnerLogos } from '../PartnerLogos'

/**
 * The site's closing: the implementing and participating organizations'
 * logos (each on its own light tile), then the supplied dark-background logo
 * on deep green (its only approved surface), the award's purpose, the main
 * pages, the three domain pages and the published policies. No contact
 * details or accounts are invented.
 */
export function SiteFooter() {
  const { award, closing, domains, pages } = useSite()
  const { t } = useLocale()

  return (
    <footer className="site-footer">
      <div className="site-footer__rule" aria-hidden="true" />
      <section className="site-footer__partners container" aria-labelledby="footer-partners-title">
        <h2 id="footer-partners-title" className="site-footer__heading site-footer__partners-title">
          {t('footer.partners')}
        </h2>
        <PartnerLogos tone="on-dark" />
      </section>
      <div className="site-footer__inner container">
        <div className="site-footer__identity">
          <BrandLogo surface="dark" className="site-footer__logo" alt={award.name} sizes="272px" loading="lazy" />
          <p className="site-footer__statement">{closing.statement}</p>
          <p className="site-footer__slogan">{award.slogan}</p>
        </div>

        <nav className="site-footer__nav" aria-label={t('footer.pages')}>
          <h2 className="site-footer__heading">{t('footer.award')}</h2>
          <ul className="site-footer__links">
            {pages.map((page) => (
              <li key={page.href}>
                <Link href={page.href}>{page.label}</Link>
              </li>
            ))}
          </ul>
        </nav>

        <nav className="site-footer__nav" aria-label={t('footer.domains')}>
          <h2 className="site-footer__heading">{t('footer.domainsHeading')}</h2>
          <ul className="site-footer__links">
            {domains.map((domain) => (
              <li key={domain.slug}>
                <Link href={`/eligibility/${domain.slug}`}>{domain.label}</Link>
              </li>
            ))}
          </ul>
        </nav>

        <nav className="site-footer__nav" aria-label={t('footer.platformNav')}>
          <h2 className="site-footer__heading">{t('footer.platform')}</h2>
          <ul className="site-footer__links">
            <li>
              <Link href="/login">{t('footer.login')}</Link>
            </li>
            <li>
              <Link href="/policies/privacy-policy">{t('footer.privacy')}</Link>
            </li>
            <li>
              <Link href="/policies/terms-of-use">{t('footer.terms')}</Link>
            </li>
          </ul>
        </nav>
      </div>
      <div className="site-footer__base container">
        <p>
          © {new Date().getFullYear()} {award.name}
        </p>
      </div>
      <div className="site-footer__band" aria-hidden="true" />
    </footer>
  )
}
