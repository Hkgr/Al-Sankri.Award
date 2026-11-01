import { useEffect } from 'react'
import { useAuth } from '../../auth/useAuth'
import { useLocale } from '../../i18n/useLocale'
import { Link } from '../../lib/router'
import { useRouter } from '../../lib/useRouter'
import { AuthLayout } from '../auth/AuthLayout'
import { JudgeLayout } from './JudgeLayout'
import { ProjectPage } from './ProjectPage'
import { ProjectsPage } from './ProjectsPage'

/**
 * /judge and one project. Signed-out visitors sign in and come back; an
 * account that is not a current judge sees a refusal.
 */
export function JudgeApp() {
  const { user } = useAuth()
  const { t } = useLocale()
  const { location, navigate } = useRouter()

  useEffect(() => {
    if (user === null) navigate(`/login?redirect=${encodeURIComponent(location.pathname + location.search)}`, { replace: true })
  }, [user, navigate, location.pathname, location.search])

  if (!user) {
    return (
      <main id="main" tabIndex={-1}>
        <p className="auth-loading" role="status">
          {t('judge.checkingSession')}
        </p>
      </main>
    )
  }

  if (!user.is_judge) {
    return (
      <main id="main" tabIndex={-1}>
        <AuthLayout title={t('judge.title')}>
          <p className="auth-panel__text">{t('judge.refused')}</p>
          <p className="auth-panel__switch">
            <Link href="/account">{t('judge.goAccount')}</Link>
          </p>
        </AuthLayout>
      </main>
    )
  }

  const detail = /^\/judge\/projects\/(\d+)$/.exec(location.pathname)

  return (
    <JudgeLayout user={user}>
      {detail ? (
        <ProjectPage key={`${user.account_context}-${detail[1]}`} user={user} applicationId={Number(detail[1])} />
      ) : (
        <ProjectsPage key={user.account_context} user={user} />
      )}
    </JudgeLayout>
  )
}
