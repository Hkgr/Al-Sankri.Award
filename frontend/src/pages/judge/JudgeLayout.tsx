import type { ReactNode } from 'react'
import logo from '../../assets/brand/award-logo-official.png'
import type { User } from '../../auth/useAuth'
import { LanguageToggle } from '../../i18n/LanguageToggle'
import { useLocale } from '../../i18n/useLocale'
import { Link } from '../../lib/router'
import { useRouter } from '../../lib/useRouter'
import '../auth/auth.css'
import '../candidate/candidate.css'
import { DamascusClock } from '../candidate/DamascusClock'
import { ProfileMenu } from '../candidate/ProfileMenu'
import './judge.css'

const AWARD_NAME = 'جائزة الدكتور عبد القادر سنكري'

/**
 * The judge portal frame: the same quiet header as the candidate area,
 * with one section, «التحكيم».
 */
export function JudgeLayout({ user, children }: { user: User; children: ReactNode }) {
  const { location } = useRouter()
  const { t } = useLocale()
  const links = [
    { href: '/judge', label: t('judge.nav') },
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
          <Link className="candidate-header__brand" href="/judge">
            <img className="award-logo" src={logo} alt={`${t(AWARD_NAME)}: ${t('judge.nav')}`} width={240} height={81} />
          </Link>
          <DamascusClock />
          <LanguageToggle />
          <ProfileMenu user={user} role={t('judge.role')} links={links} />
        </div>
        <nav className="candidate-nav" aria-label={t('judge.sections')}>
          <ul className="candidate-nav__list container">
            <li>
              <Link className="candidate-nav__link" href="/judge" aria-current={location.pathname === '/judge' ? 'page' : undefined}>
                {t('judge.nav')}
              </Link>
            </li>
          </ul>
        </nav>
      </header>
      <main id="main" tabIndex={-1} className="candidate-main">
        <div className="container">{children}</div>
      </main>
    </div>
  )
}
