import type { ReactNode } from 'react'
import logo from '../../assets/brand/logo-horizontal-light-bg.svg'
import type { User } from '../../auth/useAuth'
import { FormAlert } from '../../components/forms/FormControls'
import { Link } from '../../lib/router'
import { useRouter } from '../../lib/useRouter'
import '../auth/auth.css'
import './candidate.css'
import { DamascusClock } from './DamascusClock'
import { ProfileMenu } from './ProfileMenu'

/** The neutral award name, as in the account emails (the full title is a content decision). */
const AWARD_NAME = 'جائزة الدكتور عبد القادر سنكري'

const NAV_ITEMS = [
  { href: '/dashboard', label: 'الرئيسية' },
  { href: '/dashboard/basic-information', label: 'البيانات الأساسية' },
  { href: '/dashboard/nomination-details', label: 'فئة الترشح وبيانات العمل' },
  { href: '/dashboard/documentation', label: 'ملفات العمل وتوثيقه' },
  { href: '/dashboard/review', label: 'المراجعة والإرسال' },
]

/**
 * The candidate area's own frame: the original logo on white, the date and
 * Damascus time, the account menu, and a short navigation row instead of a
 * sidebar. The public site's header and footer are not shown here.
 */
export function CandidateLayout({ user, children }: { user: User; children: ReactNode }) {
  const { location } = useRouter()

  return (
    <div className="candidate-shell">
      <a className="skip-link" href="#main">
        انتقل إلى المحتوى الرئيسي
      </a>
      <header className="candidate-header">
        <div className="candidate-header__inner container">
          <Link className="candidate-header__brand" href="/dashboard">
            <img src={logo} alt={`${AWARD_NAME}: لوحة المترشح`} width={240} height={78} />
          </Link>
          <DamascusClock />
          <ProfileMenu user={user} />
        </div>
        <nav className="candidate-nav" aria-label="أقسام لوحة المترشح">
          <ul className="candidate-nav__list container">
            {NAV_ITEMS.map((item) => (
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
                <p>تغيّر الحساب المسجّل دخوله في هذا المتصفح، فحدّثنا الصفحة لتعرض الحساب الحالي وبياناته.</p>
              </FormAlert>
            </div>
          )}
          {children}
        </div>
      </main>
      <footer className="candidate-footer">
        <div className="candidate-footer__inner container">
          <p>{AWARD_NAME}</p>
          <Link href="/">العودة إلى الموقع العام</Link>
        </div>
      </footer>
    </div>
  )
}
