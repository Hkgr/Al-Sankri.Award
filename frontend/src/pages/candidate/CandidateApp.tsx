import { useEffect } from 'react'
import { useAuth } from '../../auth/useAuth'
import { Link } from '../../lib/router'
import { useRouter } from '../../lib/useRouter'
import { AuthLayout } from '../auth/AuthLayout'
import { BasicInformationPage } from './basic/BasicInformationPage'
import { CandidateLayout } from './CandidateLayout'
import { DashboardPage } from './DashboardPage'
import { DocumentationPage } from './documentation/DocumentationPage'
import { NominationDetailsPage } from './nomination/NominationDetailsPage'
import { ReviewPage } from './review/ReviewPage'

/**
 * /dashboard and its stage pages (basic-information, nomination-details,
 * documentation) and the review page. Signed-out visitors go to the
 * sign-in page and come back here afterwards. A judge is told that the
 * candidate pages are not for them and pointed to the judge portal; roles
 * change only through award administration. Accounts without the
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

  if (user.is_judge) {
    return (
      <main id="main" tabIndex={-1}>
        <AuthLayout title="لوحة المترشح">
          <p className="auth-panel__text">حسابك مُعيَّن حكَماً في الجائزة، فلا يمكنه استخدام لوحة المترشح أو تقديم ملف ترشح. تغيير الأدوار يتم عبر إدارة الجائزة فقط.</p>
          <p className="auth-panel__switch">
            <Link href="/judge">الانتقال إلى بوابة الحكام</Link>
          </p>
        </AuthLayout>
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
      ) : location.pathname === '/dashboard/nomination-details' ? (
        <NominationDetailsPage key={user.account_context} user={user} />
      ) : location.pathname === '/dashboard/documentation' ? (
        <DocumentationPage key={user.account_context} user={user} />
      ) : location.pathname === '/dashboard/review' ? (
        <ReviewPage key={user.account_context} user={user} />
      ) : (
        <DashboardPage key={user.account_context} user={user} />
      )}
    </CandidateLayout>
  )
}
