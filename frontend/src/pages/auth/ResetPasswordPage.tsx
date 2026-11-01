import { useState } from 'react'
import type { FormEvent } from 'react'
import { FormAlert, PasswordField, SubmitButton, TextField } from '../../components/forms/FormControls'
import { useLocale } from '../../i18n/useLocale'
import { api } from '../../lib/api'
import { Link } from '../../lib/router'
import { useRouter } from '../../lib/useRouter'
import { AuthLayout } from './AuthLayout'
import { ALERT_ID, useSubmission } from './useSubmission'

export function ResetPasswordPage() {
  const { location, navigate } = useRouter()
  const { t } = useLocale()
  const params = new URLSearchParams(location.search)
  const token = params.get('token') ?? ''
  const [email, setEmail] = useState(params.get('email') ?? '')
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const { busy, alert, fieldError, submit } = useSubmission()

  if (!token) {
    return (
      <AuthLayout title={t('auth.resetTitle')}>
        <FormAlert tone="error">
          <p>{t('auth.resetIncomplete')}</p>
        </FormAlert>
        <p className="auth-panel__switch">
          <Link href="/forgot-password">{t('auth.requestNewLink')}</Link>
        </p>
      </AuthLayout>
    )
  }

  const onSubmit = async (event: FormEvent) => {
    event.preventDefault()
    const result = await submit(() =>
      api.post('/api/auth/reset-password', { token, email, password, password_confirmation: confirmation }),
    )
    if (!result) {
      setPassword('')
      setConfirmation('')
      return
    }
    navigate('/login?reset=success', { replace: true })
  }

  const tokenError = fieldError('token')

  return (
    <AuthLayout title={t('auth.resetTitle')} intro={<p>{t('auth.resetIntro')}</p>}>
      {alert && (
        <FormAlert tone="error" id={ALERT_ID}>
          <p>{alert}</p>
          {tokenError && (
            <p>
              <Link href="/forgot-password">{t('auth.requestNewLinkShort')}</Link>
            </p>
          )}
        </FormAlert>
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
          label={t('auth.newPassword')}
          autoComplete="new-password"
          value={password}
          onChange={setPassword}
          error={fieldError('password')}
          hint={t('auth.passwordHint')}
          required
        />
        <PasswordField
          id="password_confirmation"
          label={t('auth.confirmNewPassword')}
          autoComplete="new-password"
          value={confirmation}
          onChange={setConfirmation}
          required
        />
        <SubmitButton busy={busy} busyLabel={t('auth.saving')}>
          {t('auth.savePassword')}
        </SubmitButton>
      </form>
    </AuthLayout>
  )
}
