import { homeFor, useAuth } from '../../auth/useAuth'
import { FormAlert } from '../../components/forms/FormControls'
import { useLocale } from '../../i18n/useLocale'
import { Link } from '../../lib/router'
import { useSearchParam } from '../../lib/useRouter'
import { AuthLayout } from './AuthLayout'
import { verificationResults } from './notices'

/** Where the link in the verification email lands, via the backend. */
export function VerifyEmailPage() {
  const status = useSearchParam('status')
  const { user } = useAuth()
  const { t } = useLocale()
  const result = status ? verificationResults[status] : undefined

  return (
    <AuthLayout title={t('auth.verifyTitle')}>
      {result ? (
        <FormAlert tone={result.tone}>
          <p>{t(result.key)}</p>
        </FormAlert>
      ) : (
        <p className="auth-panel__text">{t('auth.verifyHelp')}</p>
      )}
      <p className="auth-panel__switch">
        {user ? (
          <Link href={homeFor(user)}>
            {homeFor(user) === '/admin' ? t('auth.goAdmin') : homeFor(user) === '/dashboard' ? t('auth.goCandidate') : t('auth.goAccount')}
          </Link>
        ) : (
          <Link href="/login">{t('auth.login')}</Link>
        )}
      </p>
    </AuthLayout>
  )
}
