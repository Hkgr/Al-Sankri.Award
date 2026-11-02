import { useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { useAuth } from '../../auth/useAuth'
import type { User } from '../../auth/useAuth'
import { FormAlert, PasswordField, StatusIcon, SubmitButton } from '../../components/forms/FormControls'
import { useLocale } from '../../i18n/useLocale'
import { ApiError, api, errorMessage } from '../../lib/api'
import { Link } from '../../lib/router'
import { useRouter } from '../../lib/useRouter'
import { AuthLayout } from './AuthLayout'
import { usePageTitle } from './usePageTitle'
import { accountNotice } from './notices'
import { ALERT_ID, useSubmission } from './useSubmission'

/**
 * Account settings for a signed-in account: the account, its verification
 * and Google link, and sign-out. Candidates reach it from the profile menu
 * of their dashboard (/dashboard).
 */
export function AccountPage() {
  const { user } = useAuth()
  const { navigate } = useRouter()

  useEffect(() => {
    if (user === null) navigate('/login?redirect=%2Faccount', { replace: true })
  }, [user, navigate])

  if (!user) return <AccountLoading />

  return <AccountDetails user={user} />
}

function AccountLoading() {
  const { t } = useLocale()
  usePageTitle(t('account.title'))
  return (
    <div className="auth-page">
      <div className="auth-page__inner container">
        <p className="auth-loading" role="status">
          {t('account.checking')}
        </p>
      </div>
    </div>
  )
}

/** Handles a request made with a session that has ended or an account that was disabled. */
function useSessionGuard() {
  const { setUser } = useAuth()
  const { navigate } = useRouter()
  return (error: unknown): boolean => {
    if (error instanceof ApiError && error.status === 401) {
      setUser(null)
      navigate('/login?session=expired&redirect=%2Faccount', { replace: true })
      return true
    }
    if (error instanceof ApiError && error.code === 'account_disabled') {
      setUser(null)
      navigate('/login?account=disabled', { replace: true })
      return true
    }
    return false
  }
}

function AccountDetails({ user }: { user: User }) {
  const { location, navigate } = useRouter()
  const { logout } = useAuth()
  const { t } = useLocale()
  const handleSessionError = useSessionGuard()
  const params = new URLSearchParams(location.search)
  const notice = accountNotice(params)
  const welcome = params.get('welcome') === '1'
  const mailFailed = params.get('mail') === 'failed'
  const [logoutError, setLogoutError] = useState<string | null>(null)
  const [loggingOut, setLoggingOut] = useState(false)

  const onLogout = async () => {
    if (loggingOut) return
    setLoggingOut(true)
    setLogoutError(null)
    try {
      await logout()
      navigate('/', { replace: true })
    } catch (error) {
      if (!handleSessionError(error)) setLogoutError(errorMessage(error))
      setLoggingOut(false)
    }
  }

  return (
    <AuthLayout title={t('account.title')} wide>
      {user.admin_permissions.length > 0 && (
        <p className="auth-panel__text">
          <Link href="/admin">{t('auth.goAdmin')}</Link>
        </p>
      )}
      {user.is_judge && (
        <p className="auth-panel__text">
          <Link href="/judge">{t('auth.goJudging')}</Link>
        </p>
      )}
      {user.is_candidate && !user.is_judge && (
        <p className="auth-panel__text">
          <Link href="/dashboard">{t('auth.backCandidate')}</Link>
        </p>
      )}
      {welcome && (
        <FormAlert tone={mailFailed ? 'info' : 'success'}>
          <p>{mailFailed ? t('account.welcomeMailFailed') : t('account.welcome')}</p>
        </FormAlert>
      )}
      {notice && (
        <FormAlert tone={notice.tone}>
          <p>{t(notice.key)}</p>
        </FormAlert>
      )}

      <dl className="account-details">
        <div>
          <dt>{t('roles.current')}</dt>
          <dd>{user.roles?.length ? <ul>{user.roles.map((role) => <li key={`${role.scope}:${role.code}:${role.cycle?.id ?? 0}`}>{t(role.name)}{role.cycle && ` (${role.cycle.name} — ${role.cycle.year})`}</li>)}</ul> : '—'}</dd>
        </div>
        <div>
          <dt>{t('account.name')}</dt>
          <dd>{user.name}</dd>
        </div>
        <div>
          <dt>{t('auth.email')}</dt>
          <dd>
            <bdi dir="ltr">{user.email}</bdi>
          </dd>
        </div>
        <div>
          <dt>{t('account.emailStatus')}</dt>
          <dd className="account-status">
            <StatusIcon tone={user.email_verified ? 'success' : 'info'} />
            {user.email_verified ? t('account.verified') : t('account.unverified')}
          </dd>
        </div>
      </dl>
      <p className="auth-panel__text">{t(user.is_candidate ? 'roles.default' : 'roles.other')}</p>

      {!user.email_verified && <VerificationSection onSessionError={handleSessionError} />}
      <GoogleSection user={user} onSessionError={handleSessionError} />

      <section className="account-section" aria-labelledby="logout-title">
        <h2 id="logout-title" className="account-section__title">
          {t('account.logout')}
        </h2>
        {logoutError && (
          <FormAlert tone="error">
            <p>{logoutError}</p>
          </FormAlert>
        )}
        <button
          type="button"
          className="button button--secondary"
          onClick={onLogout}
          aria-busy={loggingOut || undefined}
          aria-disabled={loggingOut || undefined}
        >
          {loggingOut ? t('common.loggingOut') : t('account.logout')}
        </button>
      </section>
    </AuthLayout>
  )
}

function VerificationSection({ onSessionError }: { onSessionError: (error: unknown) => boolean }) {
  const { t } = useLocale()
  const [busy, setBusy] = useState(false)
  const [result, setResult] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)

  const resend = async () => {
    if (busy) return
    setBusy(true)
    setResult(null)
    try {
      const response = await api.post<{ message: string }>('/api/email/verification-notification')
      setResult({ tone: 'success', text: response.message })
    } catch (error) {
      if (!onSessionError(error)) setResult({ tone: 'error', text: errorMessage(error) })
    } finally {
      setBusy(false)
    }
  }

  return (
    <section className="account-section" aria-labelledby="verify-title">
      <h2 id="verify-title" className="account-section__title">
        {t('account.verifySection')}
      </h2>
      <p className="account-section__text">{t('account.verifyText')}</p>
      {result && (
        <FormAlert tone={result.tone}>
          <p>{result.text}</p>
        </FormAlert>
      )}
      <button type="button" className="button button--secondary" onClick={resend} aria-busy={busy || undefined} aria-disabled={busy || undefined}>
        {busy ? t('auth.sending') : t('account.resend')}
      </button>
    </section>
  )
}

function GoogleSection({ user, onSessionError }: { user: User; onSessionError: (error: unknown) => boolean }) {
  const { googleAvailable } = useAuth()
  const { t } = useLocale()
  const [password, setPassword] = useState('')
  const { busy, alert, fieldError, submit } = useSubmission()

  if (user.google_linked) {
    return (
      <section className="account-section" aria-labelledby="google-title">
        <h2 id="google-title" className="account-section__title">
          {t('account.googleTitle')}
        </h2>
        <p className="account-status">
          <StatusIcon tone="success" />
          {t('account.googleLinked')}
        </p>
      </section>
    )
  }

  if (!googleAvailable || !user.has_password) {
    return null
  }

  const onSubmit = async (event: FormEvent) => {
    event.preventDefault()
    const result = await submit(async () => {
      try {
        return await api.post<{ redirect: string }>('/api/account/google/link', { password })
      } catch (error) {
        if (onSessionError(error)) return undefined
        throw error
      }
    })
    setPassword('')
    // Full navigation: the backend sends the browser on to Google.
    if (result) window.location.assign(result.redirect)
  }

  return (
    <section className="account-section" aria-labelledby="google-title">
      <h2 id="google-title" className="account-section__title">
        {t('account.googleLinkTitle')}
      </h2>
      <p className="account-section__text">{t('account.googleLinkText')}</p>
      {!user.email_verified && <p className="account-section__text">{t('account.googleUnverified')}</p>}
      {alert && (
        <FormAlert tone="error" id={ALERT_ID}>
          <p>{alert}</p>
        </FormAlert>
      )}
      <form className="auth-form auth-form--inline" onSubmit={onSubmit} noValidate>
        <PasswordField
          id="link-password"
          label={t('account.currentPassword')}
          autoComplete="current-password"
          value={password}
          onChange={setPassword}
          error={fieldError('password')}
          required
        />
        <SubmitButton busy={busy} busyLabel={t('account.verifying')} variant="secondary">
          {t('account.confirmLink')}
        </SubmitButton>
      </form>
    </section>
  )
}
