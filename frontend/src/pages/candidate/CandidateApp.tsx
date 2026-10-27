import { useEffect } from 'react'
import { useAuth } from '../../auth/useAuth'
import { Link } from '../../lib/router'
import { useRouter } from '../../lib/useRouter'
import { AuthLayout } from '../auth/AuthLayout'
import { BasicInformationPage } from './basic/BasicInformationPage'
import { CandidateLayout } from './CandidateLayout'
import { DashboardPage } from './DashboardPage'

/**
 * /dashboard and /dashboard/basic-information. Signed-out visitors go to the
 * sign-in page and come back here afterwards; accounts without the
 * candidate classification are pointed to their account page.
 */
export function CandidateApp() {
  const { user } = useAuth()
  const { location, navigate } = useRouter()

  useEffect(() => {
    if (user === null) navigate(`/login?redirect=${encodeURIComponent(location.pathname)}`, { replace: true })
  }, [user, navigate, location.pathname])

  if (!user) {
    return (
      <main id="main" tabIndex={-1}>
        <p className="auth-loading" role="status">
          جارٍ التحقق من الجلسة…
        </p>
      </main>
    )
  }

  if (!user.is_candidate) {
    return (
      <main id="main" tabIndex={-1}>
        <AuthLayout title="لوحة المترشح">
          <p className="auth-panel__text">هذه اللوحة مخصّصة لحسابات المترشحين، وحسابك ليس منها.</p>
          <p className="auth-panel__switch">
            <Link href="/account">الانتقال إلى حسابي</Link>
          </p>
        </AuthLayout>
      </main>
    )
  }

  return (
    <CandidateLayout user={user}>
      {/* Keyed by the account snapshot: another account never inherits a page's state. */}
      {location.pathname === '/dashboard/basic-information' ? (
        <BasicInformationPage key={user.account_context} user={user} />
      ) : (
        <DashboardPage key={user.account_context} user={user} />
      )}
    </CandidateLayout>
  )
}
