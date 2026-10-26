import type { ReactNode } from 'react'
import './auth.css'
import { usePageTitle } from './usePageTitle'

/**
 * The shared frame for account pages: the guide's line pattern as a quiet
 * band behind a white panel with a single gold rule (identity-guidelines.md
 * §7.4 and §8.2–8.3).
 */
export function AuthLayout({ title, intro, children, wide = false }: { title: string; intro?: ReactNode; children: ReactNode; wide?: boolean }) {
  usePageTitle(title)

  return (
    <div className="auth-page">
      <div className={`auth-page__inner container${wide ? ' auth-page__inner--wide' : ''}`}>
        <section className="auth-panel" aria-labelledby="auth-title">
          <h1 id="auth-title" className="auth-panel__title">
            {title}
          </h1>
          {intro && <div className="auth-panel__intro">{intro}</div>}
          {children}
        </section>
      </div>
    </div>
  )
}

export function Divider({ children }: { children: ReactNode }) {
  return (
    <div className="auth-divider">
      <span>{children}</span>
    </div>
  )
}
