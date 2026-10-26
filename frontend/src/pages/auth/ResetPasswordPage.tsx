import { useState } from 'react'
import type { FormEvent } from 'react'
import { FormAlert, PasswordField, SubmitButton, TextField } from '../../components/forms/FormControls'
import { api } from '../../lib/api'
import { Link } from '../../lib/router'
import { useRouter } from '../../lib/useRouter'
import { AuthLayout } from './AuthLayout'
import { PASSWORD_HINT } from './RegisterPage'
import { ALERT_ID, useSubmission } from './useSubmission'

export function ResetPasswordPage() {
  const { location, navigate } = useRouter()
  const params = new URLSearchParams(location.search)
  const token = params.get('token') ?? ''
  const [email, setEmail] = useState(params.get('email') ?? '')
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const { busy, alert, fieldError, submit } = useSubmission()

  if (!token) {
    return (
      <AuthLayout title="تعيين كلمة مرور جديدة">
        <FormAlert tone="error">
          <p>هذا الرابط غير مكتمل. افتح الرابط كما وصلك في الرسالة، أو اطلب رابطاً جديداً.</p>
        </FormAlert>
        <p className="auth-panel__switch">
          <Link href="/forgot-password">طلب رابط جديد</Link>
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
    <AuthLayout title="تعيين كلمة مرور جديدة" intro={<p>اختر كلمة مرور جديدة لحسابك.</p>}>
      {alert && (
        <FormAlert tone="error" id={ALERT_ID}>
          <p>{alert}</p>
          {tokenError && (
            <p>
              <Link href="/forgot-password">اطلب رابطاً جديداً</Link>
            </p>
          )}
        </FormAlert>
      )}

      <form className="auth-form" onSubmit={onSubmit} noValidate>
        <TextField
          id="email"
          label="البريد الإلكتروني"
          type="email"
          autoComplete="username"
          value={email}
          onChange={setEmail}
          error={fieldError('email')}
          required
        />
        <PasswordField
          id="password"
          label="كلمة المرور الجديدة"
          autoComplete="new-password"
          value={password}
          onChange={setPassword}
          error={fieldError('password')}
          hint={PASSWORD_HINT}
          required
        />
        <PasswordField
          id="password_confirmation"
          label="تأكيد كلمة المرور الجديدة"
          autoComplete="new-password"
          value={confirmation}
          onChange={setConfirmation}
          required
        />
        <SubmitButton busy={busy} busyLabel="جارٍ الحفظ…">
          حفظ كلمة المرور
        </SubmitButton>
      </form>
    </AuthLayout>
  )
}
