import { useState } from 'react'
import type { FormEvent } from 'react'
import { FormAlert, SubmitButton, TextField } from '../../components/forms/FormControls'
import { useLocale } from '../../i18n/useLocale'
import { api } from '../../lib/api'
import { Link } from '../../lib/router'
import { AuthLayout } from './AuthLayout'
import { ALERT_ID, useSubmission } from './useSubmission'

export function ForgotPasswordPage() {
  const { t } = useLocale()
  const [email, setEmail] = useState('')
  const [sent, setSent] = useState<string | null>(null)
  const { busy, alert, fieldError, submit } = useSubmission()

  const onSubmit = async (event: FormEvent) => {
    event.preventDefault()
    setSent(null)
    const result = await submit(() => api.post<{ message: string }>('/api/auth/forgot-password', { email }))
    if (result) setSent(result.message)
  }

  return (
    <AuthLayout title={t('auth.forgotTitle')} intro={<p>{t('auth.forgotIntro')}</p>}>
      {alert && (
        <FormAlert tone="error" id={ALERT_ID}>
          <p>{alert}</p>
        </FormAlert>
      )}
      {sent && (
        <FormAlert tone="success">
          <p>{sent}</p>
          <p>{t('auth.forgotNotArrived')}</p>
        </FormAlert>
      )}

      <form className="auth-form" onSubmit={onSubmit} noValidate>
        <TextField
          id="email"
          label={t('auth.email')}
          type="email"
          autoComplete="email"
          value={email}
          onChange={setEmail}
          error={fieldError('email')}
          required
        />
        <SubmitButton busy={busy} busyLabel={t('auth.sending')}>
          {t('auth.sendReset')}
        </SubmitButton>
      </form>

      <p className="auth-panel__switch">
        <Link href="/login">{t('auth.backToLogin')}</Link>
      </p>
    </AuthLayout>
  )
}
