import { useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { useAuth } from '../../auth/useAuth'
import type { User } from '../../auth/useAuth'
import { FormAlert } from '../../components/forms/FormControls'
import { api, errorMessage } from '../../lib/api'
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
 * sign-in page and come back here afterwards. A current judge is offered
 * registration as a participant, which cancels the judge role. Accounts
 * without the candidate classification are pointed to their account page.
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
        <ParticipatePanel user={user} />
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

function ParticipatePanel({ user }: { user: User }) {
  const { setUser } = useAuth()
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (busy) return
    setBusy(true)
    setError(null)
    try {
      const saved = await api.post<{ data: User }>('/api/account/participate', { account_context: user.account_context })
      setUser(saved.data)
    } catch (caught) {
      setError(errorMessage(caught))
      setBusy(false)
    }
  }

  return (
    <AuthLayout title="التسجيل كمشارك">
      <p className="auth-panel__text">تسجيلك كمشارك يلغي صفتك كمحكّم، ولن تتمكن بعدها من دخول بوابة الحكم.</p>
      {error && (
        <FormAlert tone="error">
          <p>{error}</p>
        </FormAlert>
      )}
      <form className="form" onSubmit={onSubmit} noValidate>
        <button type="submit" className="button button--primary" disabled={busy}>
          {busy ? 'جارٍ التسجيل…' : 'التسجيل كمشارك'}
        </button>
      </form>
    </AuthLayout>
  )
}
