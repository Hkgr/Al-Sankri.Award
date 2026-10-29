import type { ReactNode } from 'react'
import logo from '../../assets/brand/logo-horizontal-light-bg.svg'
import type { User } from '../../auth/useAuth'
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
  const links = [
    { href: '/judge', label: 'التحكيم' },
    { href: '/dashboard', label: 'التسجيل كمشارك' },
    { href: '/account', label: 'إعدادات الحساب' },
    { href: '/', label: 'العودة إلى الموقع العام' },
  ]

  return (
    <div className="candidate-shell">
      <a className="skip-link" href="#main">
        انتقل إلى المحتوى الرئيسي
      </a>
      <header className="candidate-header">
        <div className="candidate-header__inner container">
          <Link className="candidate-header__brand" href="/judge">
            <img src={logo} alt={`${AWARD_NAME}: التحكيم`} width={240} height={78} />
          </Link>
          <DamascusClock />
          <ProfileMenu user={user} role="حكَم" links={links} />
        </div>
        <nav className="candidate-nav" aria-label="أقسام بوابة الحكم">
          <ul className="candidate-nav__list container">
            <li>
              <Link className="candidate-nav__link" href="/judge" aria-current={location.pathname === '/judge' ? 'page' : undefined}>
                التحكيم
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
