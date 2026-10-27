import { useEffect, useRef, useState } from 'react'
import logo from '../../assets/brand/logo-horizontal-light-bg.svg'
import { homeFor, useAuth } from '../../auth/useAuth'
import { award, sectionLinks } from '../../content/home'
import { Link } from '../../lib/router'
import { useRouter } from '../../lib/useRouter'

// Matches the breakpoint in layout.css where the navigation becomes inline.
const INLINE_NAV_QUERY = '(min-width: 64rem)'

/**
 * «تسجيل» (signed out) or «حسابي» (signed in). The header renders it twice,
 * beside the menu toggle on small screens and after the navigation on wide
 * ones; CSS shows exactly one, so DOM order matches visual order in both.
 */
function AccountLink({ placement }: { placement: 'compact' | 'wide' }) {
  const { user } = useAuth()
  const { location } = useRouter()
  const signedIn = Boolean(user)
  const href = user ? homeFor(user) : '/login'

  return (
    <Link
      className={`account-link account-link--${placement}`}
      href={href}
      aria-current={location.pathname === href ? 'page' : undefined}
    >
      <svg className="account-link__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        <circle cx="12" cy="8" r="4" />
        <path d="M4.5 20.5c0-4 3.4-6.5 7.5-6.5s7.5 2.5 7.5 6.5" />
      </svg>
      <span className="account-link__label">{signedIn ? 'حسابي' : 'تسجيل'}</span>
    </Link>
  )
}

/**
 * Site header with a disclosure-style mobile menu. The menu is not modal:
 * it pushes content down, so focus is not trapped. Escape closes it and
 * returns focus to the toggle; following a link or clicking outside closes it.
 * Section links point at the homepage (/#…), so they work from every page.
 */
export function SiteHeader() {
  const [menuOpen, setMenuOpen] = useState(false)
  const headerRef = useRef<HTMLElement>(null)
  const toggleRef = useRef<HTMLButtonElement>(null)

  useEffect(() => {
    if (!menuOpen) return

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key !== 'Escape') return
      setMenuOpen(false)
      toggleRef.current?.focus()
    }
    const onPointerDown = (event: PointerEvent) => {
      if (!headerRef.current?.contains(event.target as Node)) setMenuOpen(false)
    }
    const inlineNav = window.matchMedia(INLINE_NAV_QUERY)
    const onViewportChange = () => {
      if (inlineNav.matches) setMenuOpen(false)
    }

    document.addEventListener('keydown', onKeyDown)
    document.addEventListener('pointerdown', onPointerDown)
    inlineNav.addEventListener('change', onViewportChange)
    return () => {
      document.removeEventListener('keydown', onKeyDown)
      document.removeEventListener('pointerdown', onPointerDown)
      inlineNav.removeEventListener('change', onViewportChange)
    }
  }, [menuOpen])

  return (
    <header id="top" className="site-header" ref={headerRef}>
      <a className="skip-link" href="#main">
        انتقل إلى المحتوى الرئيسي
      </a>
      <div className="site-header__inner container">
        <Link className="site-header__brand" href="/">
          <img src={logo} alt={award.name} width={240} height={78} />
        </Link>

        <div className="site-header__actions">
          <AccountLink placement="compact" />
          <button
            ref={toggleRef}
            type="button"
            className="site-header__toggle"
            aria-expanded={menuOpen}
            aria-controls="site-nav"
            onClick={() => setMenuOpen((open) => !open)}
          >
            <svg className="site-header__toggle-icon" viewBox="0 0 24 24" aria-hidden="true">
              {menuOpen ? (
                <path d="M6 6l12 12M18 6L6 18" />
              ) : (
                <path d="M4 7h16M4 12h16M4 17h16" />
              )}
            </svg>
            <span className="site-header__toggle-label">القائمة</span>
          </button>
        </div>

        <nav
          id="site-nav"
          className="site-nav"
          aria-label="أقسام الصفحة الرئيسية"
          data-open={menuOpen}
        >
          <ul className="site-nav__list">
            {sectionLinks.map((link) => (
              <li key={link.href}>
                <Link className="site-nav__link" href={`/${link.href}`} onClick={() => setMenuOpen(false)}>
                  {link.label}
                </Link>
              </li>
            ))}
          </ul>
        </nav>
        <AccountLink placement="wide" />
      </div>
    </header>
  )
}
