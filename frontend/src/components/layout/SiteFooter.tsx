import logo from '../../assets/brand/logo-horizontal-dark-bg.svg'
import { award, closing, domains, pages } from '../../content/site'
import { Link } from '../../lib/router'
import { OrgLink } from '../OrgLink'

/**
 * The site's closing: the supplied dark-background logo on deep green (its
 * only approved surface), the award's purpose, the four pages, the three
 * domain pages, the implementing organisation and the published policies.
 * No contact details, accounts or sponsor marks are invented.
 */
export function SiteFooter() {
  return (
    <footer className="site-footer">
      <div className="site-footer__rule" aria-hidden="true" />
      <div className="site-footer__inner container">
        <div className="site-footer__identity">
          <img className="site-footer__logo" src={logo} alt={award.name} width={260} height={84} />
          <p className="site-footer__statement">{closing.statement}</p>
          <p className="site-footer__slogan">{award.slogan}</p>
          <p className="site-footer__org">
            تنفّذها <OrgLink org="rowad" className="org-link--on-dark" />
          </p>
        </div>

        <nav className="site-footer__nav" aria-label="صفحات الموقع">
          <h2 className="site-footer__heading">الجائزة</h2>
          <ul className="site-footer__links">
            {pages.map((page) => (
              <li key={page.href}>
                <Link href={page.href}>{page.label}</Link>
              </li>
            ))}
          </ul>
        </nav>

        <nav className="site-footer__nav" aria-label="محاور الجائزة">
          <h2 className="site-footer__heading">المحاور</h2>
          <ul className="site-footer__links">
            {domains.map((domain) => (
              <li key={domain.slug}>
                <Link href={`/eligibility/${domain.slug}`}>{domain.label}</Link>
              </li>
            ))}
          </ul>
        </nav>

        <nav className="site-footer__nav" aria-label="المنصة والسياسات">
          <h2 className="site-footer__heading">المنصة</h2>
          <ul className="site-footer__links">
            <li>
              <Link href="/login">الدخول إلى المنصة</Link>
            </li>
            <li>
              <Link href="/policies/privacy-policy">سياسة الخصوصية</Link>
            </li>
            <li>
              <Link href="/policies/terms-of-use">شروط الاستخدام</Link>
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
