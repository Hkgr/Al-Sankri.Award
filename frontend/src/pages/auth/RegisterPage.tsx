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
import { ALERT_ID, useSubmission } from './useSubmission'

export function RegisterPage() {
  const { navigate } = useRouter()
  const { setUser, googleAvailable } = useAuth()
  const { t } = useLocale()
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const { busy, alert, fieldError, submit } = useSubmission()

  const onSubmit = async (event: FormEvent) => {
    event.preventDefault()
    const result = await submit(() =>
      api.post<{ data: User; redirect: string; verification_email_sent: boolean }>('/api/auth/register', {
        name,
        email,
        password,
        password_confirmation: confirmation,
      }),
    )
    if (!result) {
      setPassword('')
      setConfirmation('')
      return
    }
    setUser(result.data)
    // New accounts are candidates: the backend sends them to the dashboard.
    navigate(`${result.redirect}?welcome=1${result.verification_email_sent ? '' : '&mail=failed'}`, { replace: true })
  }

  return (
    <AuthLayout title={t('auth.registerTitle')} intro={<p>{t('auth.registerIntro')}</p>}>
      {alert && (
        <FormAlert tone="error" id={ALERT_ID}>
          <p>{alert}</p>
        </FormAlert>
      )}

      <form className="auth-form" onSubmit={onSubmit} noValidate>
        <TextField id="name" label={t('auth.fullName')} autoComplete="name" value={name} onChange={setName} error={fieldError('name')} required />
        <TextField
          id="email"
          label={t('auth.email')}
          type="email"
          autoComplete="email"
          value={email}
          onChange={setEmail}
          error={fieldError('email')}
          hint={t('auth.emailHint')}
          required
        />
        <PasswordField
          id="password"
          label={t('auth.password')}
          autoComplete="new-password"
          value={password}
          onChange={setPassword}
          error={fieldError('password')}
          hint={t('auth.passwordHint')}
          required
        />
        <PasswordField
          id="password_confirmation"
          label={t('auth.confirmPassword')}
          autoComplete="new-password"
          value={confirmation}
          onChange={setConfirmation}
          error={fieldError('password_confirmation')}
          required
        />
        <SubmitButton busy={busy} busyLabel={t('auth.creating')}>
          {t('auth.createAccount')}
        </SubmitButton>
      </form>

      <Divider>{t('auth.or')}</Divider>
      <GoogleButton available={googleAvailable} />

      <p className="auth-panel__switch">
        {t('auth.haveAccount')} <Link href="/login">{t('auth.login')}</Link>
      </p>
    </AuthLayout>
  )
}
