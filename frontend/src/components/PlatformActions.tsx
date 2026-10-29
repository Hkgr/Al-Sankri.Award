import { homeFor, useAuth } from '../auth/useAuth'
import { Link } from '../lib/router'
import type { SchedulePhase } from '../lib/schedule'

/**
 * The way into the platform, matching what is actually possible: while
 * applications are open a signed-out visitor is invited to apply (sign up);
 * otherwise to sign in. A signed-in account goes to its own area (the
 * role-aware destination). Account access stays available after closure.
 */
export function PlatformActions({ phase, secondary }: { phase: SchedulePhase; secondary?: { href: string; label: string } }) {
  const { user } = useAuth()
  const primary = user ? { href: homeFor(user), label: 'الانتقال إلى المنصة' } : phase === 'open' ? { href: '/register', label: 'قدّم ملف ترشحك' } : { href: '/login', label: 'الدخول إلى المنصة' }

  return (
    <div className="platform-actions">
      <Link className="button button--accent" href={primary.href}>
        {primary.label}
      </Link>
      {secondary && (
        <Link className="button button--ghost" href={secondary.href}>
          {secondary.label}
        </Link>
      )}
    </div>
  )
}
