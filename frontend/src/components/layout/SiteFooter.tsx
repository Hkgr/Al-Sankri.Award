import { BrandLogo } from '../BrandLogo'
import { ContactLinks } from '../ContactLinks'
import { useSite } from '../../content/useSite'
import { useLocale } from '../../i18n/useLocale'
import { Link } from '../../lib/router'
import { PartnerLogos } from '../PartnerLogos'

/**
 * The site's closing: a white card of the implementing and participating
 * organizations floats across the top edge of the deep-green footer (their
 * dark-ink logos stay on white); then the supplied dark-background logo on
 * deep green (its only approved surface), the award's purpose, the main pages,
 * the three domain pages, the published policies and the owner's contact
 * details for enquiries and cooperation, over a faint slogan watermark.
 */
export function SiteFooter() {
  const { award, closing, domains, pages } = useSite()
  const { t } = useLocale()

  return (
    <footer className="site-footer">
      <section className="site-footer__partners" aria-labelledby="footer-partners-title">
        <div className="container">
          <h2 id="footer-partners-title" className="visually-hidden">
            {t('footer.partners')}
          </h2>
          <PartnerLogos />
        </div>
      </section>

      <div className="site-footer__body">
        {/* The slogan twice, so its slow drift loops without a jump. */}
        <p className="site-footer__watermark" aria-hidden="true">
          {award.slogan} · {award.slogan} ·
        </p>
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

          <section className="site-footer__contact" aria-labelledby="footer-contact-title">
            <h2 id="footer-contact-title" className="site-footer__heading">
              {t('contact.title')}
            </h2>
            <p className="site-footer__contact-note">{t('contact.purpose')}</p>
            <ContactLinks />
          </section>
        </div>
        <div className="site-footer__base container">
          <p>
            © {new Date().getFullYear()} {award.name}
          </p>
          <p>{t('footer.rights')}</p>
        </div>
        <div className="site-footer__band" aria-hidden="true" />
      </div>
    </footer>
  )
}
