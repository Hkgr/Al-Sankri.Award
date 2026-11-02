import type { ReactNode } from 'react'
import logo from '../../assets/brand/award-logo-official.png'
import type { User } from '../../auth/useAuth'
import { FormAlert } from '../../components/forms/FormControls'
import { LanguageToggle } from '../../i18n/LanguageToggle'
import { useLocale } from '../../i18n/useLocale'
import { Link } from '../../lib/router'
import { useRouter } from '../../lib/useRouter'
import '../auth/auth.css'
import './candidate.css'
import { DamascusClock } from './DamascusClock'
import { ProfileMenu } from './ProfileMenu'

/** The neutral award name, as in the account emails (the full title is a content decision). */
const AWARD_NAME = 'جائزة الدكتور عبد القادر سنكري'

/**
 * The candidate area's own frame: the original logo on white, the date and
 * Damascus time, the account menu, and a short navigation row instead of a
 * sidebar. The public site's header and footer are not shown here.
 */
export function CandidateLayout({ user, children }: { user: User; children: ReactNode }) {
  const { location } = useRouter()
  const { t } = useLocale()
  const navItems = [
    { href: '/dashboard', label: t('candidate.home') },
    { href: '/dashboard/basic-information', label: t('candidate.basic') },
    { href: '/dashboard/nomination-details', label: t('candidate.nomination') },
    { href: '/dashboard/documentation', label: t('candidate.documentation') },
    { href: '/dashboard/review', label: t('candidate.review') },
  ]
  const links = [
    { href: '/dashboard', label: t('candidate.dashboard') },
    { href: '/account', label: t('common.accountSettings') },
    { href: '/', label: t('common.publicSite') },
  ]

  return (
    <div className="candidate-shell">
      <a className="skip-link" href="#main">
        {t('common.skip')}
      </a>
      <header className="candidate-header">
        <div className="candidate-header__inner container">
          <Link className="candidate-header__brand" href="/dashboard">
            <img className="award-logo" src={logo} alt={`${t(AWARD_NAME)}: ${t('candidate.dashboard')}`} width={240} height={81} />
          </Link>
          <DamascusClock />
          <LanguageToggle />
          <ProfileMenu user={user} role={t('candidate.role')} links={links} />
        </div>
        <nav className="candidate-nav" aria-label={t('candidate.nav')}>
          <ul className="candidate-nav__list container">
            {navItems.map((item) => (
              <li key={item.href}>
                <Link
                  className="candidate-nav__link"
                  href={item.href}
                  aria-current={location.pathname === item.href ? 'page' : undefined}
                >
                  {item.label}
                </Link>
              </li>
            ))}
          </ul>
        </nav>
      </header>
      <main id="main" tabIndex={-1} className="candidate-main">
        <div className="container">
          {new URLSearchParams(location.search).get('account') === 'changed' && (
            <div className="candidate-page candidate-page--notice">
              <FormAlert tone="info">
                <p>{t('candidate.accountChanged')}</p>
              </FormAlert>
            </div>
          )}
          {children}
        </div>
      </main>
      <footer className="candidate-footer">
        <div className="candidate-footer__inner container">
          <p>{AWARD_NAME}</p>
          <Link href="/">{t('common.publicSite')}</Link>
        </div>
      </footer>
    </div>
  )
}
