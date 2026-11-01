import { homeFor, useAuth } from '../auth/useAuth'
import { useLocale } from '../i18n/useLocale'
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
  const { t } = useLocale()
  const primary = user
    ? { href: homeFor(user), label: t('actions.goPlatform') }
    : phase === 'open'
      ? { href: '/register', label: t('actions.apply') }
      : { href: '/login', label: t('actions.enterPlatform') }

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
