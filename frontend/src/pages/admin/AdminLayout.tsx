import { useEffect, useId, useState } from 'react'
import type { ReactNode } from 'react'
import logo from '../../assets/brand/logo-horizontal-dark-bg.svg'
import type { User } from '../../auth/useAuth'
import { Link } from '../../lib/router'
import { useRouter } from '../../lib/useRouter'
import '../auth/auth.css'
import '../candidate/candidate.css'
import { DamascusClock } from '../candidate/DamascusClock'
import { ProfileMenu } from '../candidate/ProfileMenu'
import type { AdminCycle } from './adminApi'
import { cycleLabel } from './adminApi'
import { ALL_CYCLES } from './applications/tableState'
import './admin.css'

const AWARD_NAME = 'جائزة الدكتور عبد القادر سنكري'

/** In this exact order; the last two are not available in this phase. */
const NAV_ITEMS: { href: string; label: string; soon?: boolean }[] = [
  { href: '/admin', label: 'الرئيسية' },
  { href: '/admin/users', label: 'المستخدمون' },
  { href: '/admin/applications', label: 'ملفات الترشح' },
  { href: '/admin/committees', label: 'لجان الحكام' },
  { href: '/admin/schedule', label: 'المواعيد', soon: true },
  { href: '/admin/log', label: 'السجل', soon: true },
]

const PROFILE_LINKS = [
  { href: '/admin', label: 'لوحة الإدارة' },
  { href: '/account', label: 'إعدادات الحساب' },
  { href: '/', label: 'العودة إلى الموقع العام' },
]

/**
 * The administration frame: a compact deep-green header (the supplied
 * dark-background logo, «لوحة الإدارة», the selected cycle, the Damascus
 * date and time, the account menu) and one horizontal navigation row,
 * which collapses behind a «القائمة» button on narrow screens. The public
 * header, footer and candidate frame are not used here.
 */
export function AdminLayout({
  user,
  cycles,
  cycleValue,
  includeAll,
  onCycleChange,
  children,
}: {
  user: User
  cycles: AdminCycle[]
  /** The cycle shown: the selected one, or the table's scope on «ملفات الترشح» (possibly «all»). */
  cycleValue: string | null
  /** «كل الدورات» is a choice where the page has that scope (the applications table). */
  includeAll: boolean
  onCycleChange: (value: string) => void
  children: ReactNode
}) {
  const { location } = useRouter()
  const [menuOpen, setMenuOpen] = useState(false)
  const navId = useId()
  const cycleSelectId = useId()
  const section = location.pathname.startsWith('/admin/applications') ? '/admin/applications' : location.pathname

  useEffect(() => {
    // A chosen page closes the narrow-screen menu.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setMenuOpen(false)
  }, [location.pathname])

  return (
    <div className="admin-shell">
      <a className="skip-link" href="#main">
        انتقل إلى المحتوى الرئيسي
      </a>
      <header className="admin-header">
        <div className="admin-header__inner">
          <Link className="admin-header__brand" href="/admin">
            <img src={logo} alt={AWARD_NAME} width={200} height={65} />
          </Link>
          <p className="admin-header__title">لوحة الإدارة</p>
          <div className="admin-header__cycle">
            <label htmlFor={cycleSelectId}>الدورة</label>
            {cycles.length > 0 && cycleValue ? (
              <select id={cycleSelectId} value={cycleValue} onChange={(event) => onCycleChange(event.target.value)}>
                {includeAll && <option value={ALL_CYCLES}>كل الدورات</option>}
                {cycles.map((item) => (
                  <option key={item.id} value={item.id}>
                    {cycleLabel(item)}
                  </option>
                ))}
              </select>
            ) : (
              <span id={cycleSelectId} className="admin-header__cycle-empty">
                لا توجد دورات بعد
              </span>
            )}
          </div>
          <DamascusClock />
          <ProfileMenu user={user} role="إدارة الجائزة" links={PROFILE_LINKS} />
        </div>
        <nav className="admin-nav" aria-label="أقسام لوحة الإدارة">
          <button type="button" className="admin-nav__toggle" aria-expanded={menuOpen} aria-controls={navId} onClick={() => setMenuOpen((open) => !open)}>
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
              <path d={menuOpen ? 'M6 6l12 12M18 6L6 18' : 'M4 7h16M4 12h16M4 17h16'} />
            </svg>
            القائمة
          </button>
          <ul id={navId} className="admin-nav__list" data-open={menuOpen}>
            {NAV_ITEMS.map((item) => (
              <li key={item.href}>
                {item.soon ? (
                  <span className="admin-nav__link admin-nav__link--soon" aria-disabled="true">
                    {item.label}
                    <span className="admin-nav__soon">قريباً</span>
                  </span>
                ) : (
                  <Link className="admin-nav__link" href={item.href} aria-current={section === item.href ? 'page' : undefined}>
                    {item.label}
                  </Link>
                )}
              </li>
            ))}
          </ul>
        </nav>
      </header>
      <main id="main" tabIndex={-1} className="admin-main">
        {children}
      </main>
    </div>
  )
}
