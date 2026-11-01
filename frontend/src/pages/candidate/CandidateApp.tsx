import { useEffect } from 'react'
import { useAuth } from '../../auth/useAuth'
import { useLocale } from '../../i18n/useLocale'
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
  const { t } = useLocale()

  useEffect(() => {
    if (user === null) navigate(`/login?redirect=${encodeURIComponent(location.pathname)}`, { replace: true })
  }, [user, navigate, location.pathname])

  if (!user) {
    return (
      <main id="main" tabIndex={-1}>
        <p className="auth-loading" role="status">
          {t('judge.checkingSession')}
        </p>
      </main>
    )
  }

  if (user.is_judge) {
    return (
      <main id="main" tabIndex={-1}>
        <AuthLayout title={t('candidate.dashboard')} languageToggle>
          <p className="auth-panel__text">{t('candidate.judgeBlocked')}</p>
          <p className="auth-panel__switch">
            <Link href="/judge">{t('candidate.goJudge')}</Link>
          </p>
        </AuthLayout>
      </main>
    )
  }

  if (!user.is_candidate) {
    return (
      <main id="main" tabIndex={-1}>
        <AuthLayout title={t('candidate.dashboard')} languageToggle>
          <p className="auth-panel__text">{t('candidate.refused')}</p>
          <p className="auth-panel__switch">
            <Link href="/account">{t('auth.goAccount')}</Link>
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
