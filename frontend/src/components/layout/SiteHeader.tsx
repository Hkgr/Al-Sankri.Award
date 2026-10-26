import { useEffect, useRef, useState } from 'react'
import logo from '../../assets/brand/logo-horizontal-light-bg.svg'
import { award, sectionLinks } from '../../content/home'

// Matches the breakpoint in layout.css where the navigation becomes inline.
const INLINE_NAV_QUERY = '(min-width: 64rem)'

/**
 * Site header with a disclosure-style mobile menu. The menu is not modal:
 * it pushes content down, so focus is not trapped. Escape closes it and
 * returns focus to the toggle; following a link or clicking outside closes it.
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
        <a className="site-header__brand" href="#top">
          <img src={logo} alt={award.name} width={240} height={78} />
        </a>

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

        <nav
          id="site-nav"
          className="site-nav"
          aria-label="أقسام الصفحة"
          data-open={menuOpen}
        >
          <ul className="site-nav__list">
            {sectionLinks.map((link) => (
              <li key={link.href}>
                <a className="site-nav__link" href={link.href} onClick={() => setMenuOpen(false)}>
                  {link.label}
                </a>
              </li>
            ))}
          </ul>
        </nav>
      </div>
    </header>
  )
}
