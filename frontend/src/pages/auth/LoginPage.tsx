import { useState } from 'react'
import type { FormEvent } from 'react'
import { useAuth } from '../../auth/useAuth'
import type { User } from '../../auth/useAuth'
import { FormAlert, PasswordField, SubmitButton, TextField } from '../../components/forms/FormControls'
import { GoogleButton } from '../../components/GoogleButton'
import { useLocale } from '../../i18n/useLocale'
import { api } from '../../lib/api'
import { Link } from '../../lib/router'
import { useRouter } from '../../lib/useRouter'
import { AuthLayout, Divider } from './AuthLayout'
import { loginNotice } from './notices'
import { ALERT_ID, useSubmission } from './useSubmission'

/** Where to go after a successful sign-in: client-side for app pages. */
function useFollowRedirect() {
  const { navigate } = useRouter()
  return (target: string) => {
    const path = target.split('?')[0]
    if (['/account', '/dashboard', '/dashboard/basic-information'].includes(path)) navigate(target, { replace: true })
    else window.location.assign(target)
  }
}

export function LoginPage() {
  const { location } = useRouter()
  const { setUser, googleAvailable } = useAuth()
  const { t } = useLocale()
  const followRedirect = useFollowRedirect()
  const params = new URLSearchParams(location.search)
  const requested = params.get('redirect') ?? undefined
  const notice = loginNotice(params)

  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const { busy, alert, fieldError, submit } = useSubmission()

  const onSubmit = async (event: FormEvent) => {
    event.preventDefault()
    const result = await submit(() =>
      api.post<{ data: User; redirect: string }>('/api/auth/login', { email, password, redirect: requested }),
    )
    if (!result) {
      setPassword('')
      return
    }
    setUser(result.data)
    followRedirect(result.redirect)
  }

  return (
    <AuthLayout title={t('auth.login')} intro={<p>{t('auth.loginIntro')}</p>}>
      {alert ? (
        <FormAlert tone="error" id={ALERT_ID}>
          <p>{alert}</p>
        </FormAlert>
      ) : (
        notice && (
          <FormAlert tone={notice.tone}>
            <p>{t(notice.key)}</p>
          </FormAlert>
        )
      )}

      <form className="auth-form" onSubmit={onSubmit} noValidate>
        <TextField
          id="email"
          label={t('auth.email')}
          type="email"
          autoComplete="username"
          value={email}
          onChange={setEmail}
          error={fieldError('email')}
          required
        />
        <PasswordField
          id="password"
          label={t('auth.password')}
          autoComplete="current-password"
          value={password}
          onChange={setPassword}
          error={fieldError('password')}
          required
        />
        <p className="auth-form__aside">
          <Link href="/forgot-password">{t('auth.forgot')}</Link>
        </p>
        <SubmitButton busy={busy} busyLabel={t('auth.loggingIn')}>
          {t('auth.login')}
        </SubmitButton>
      </form>

      <Divider>{t('auth.or')}</Divider>
      <GoogleButton available={googleAvailable} redirect={requested} />

      <p className="auth-panel__switch">
        {t('auth.noAccount')} <Link href="/register">{t('auth.createNew')}</Link>
      </p>
    </AuthLayout>
  )
}
