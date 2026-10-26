import logo from '../../assets/brand/logo-horizontal-dark-bg.svg'
import { award, photoCredit, sectionLinks } from '../../content/home'
import { Link } from '../../lib/router'

export function SiteFooter() {
  return (
    <footer className="site-footer">
      <div className="site-footer__inner container">
        <div className="site-footer__identity">
          {/* The dark-background logo is supplied artwork for #14413D only. */}
          <img src={logo} alt={award.name} width={240} height={78} />
          <p className="site-footer__org">
            تنفّذها <a href={award.organizationUrl}>{award.organization}</a>
          </p>
        </div>

        <nav className="site-footer__nav" aria-label="روابط الصفحة">
          <ul>
            <li>
              <Link href="/">الرئيسية</Link>
            </li>
            {sectionLinks.map((link) => (
              <li key={link.href}>
                <Link href={`/${link.href}`}>{link.label}</Link>
              </li>
            ))}
          </ul>
        </nav>

        <p className="site-footer__credit">
          صور الدكتور عبد القادر سنكري: منظمة{' '}
          <bdi lang="en" dir="ltr">
            HAND
          </bdi>
          ، من صفحة{' '}
          <a href={photoCredit.aboutUrl} hrefLang="en">
            «من نحن»
          </a>{' '}
          وخبر{' '}
          <a href={photoCredit.visitUrl} hrefLang="en">
            زيارة الجامع الأموي
          </a>{' '}
          (بالإنجليزية).
        </p>
      </div>
      <div className="site-footer__band" />
    </footer>
  )
}
