import { useState } from 'react'
import type { FormEvent } from 'react'
import { useAuth } from '../../auth/useAuth'
import type { User } from '../../auth/useAuth'
import { FormAlert, PasswordField, SubmitButton, TextField } from '../../components/forms/FormControls'
import { GoogleButton } from '../../components/GoogleButton'
import { api } from '../../lib/api'
import { Link } from '../../lib/router'
import { useRouter } from '../../lib/useRouter'
import { AuthLayout, Divider } from './AuthLayout'
import { ALERT_ID, useSubmission } from './useSubmission'

export const PASSWORD_HINT = '8 أحرف على الأقل، وتتضمن حرفاً ورقماً واحداً على الأقل.'

export function RegisterPage() {
  const { navigate } = useRouter()
  const { setUser, googleAvailable } = useAuth()
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const { busy, alert, fieldError, submit } = useSubmission()

  const onSubmit = async (event: FormEvent) => {
    event.preventDefault()
    const result = await submit(() =>
      api.post<{ data: User; verification_email_sent: boolean }>('/api/auth/register', {
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
    navigate(result.verification_email_sent ? '/account?welcome=1' : '/account?welcome=1&mail=failed', { replace: true })
  }

  return (
    <AuthLayout
      title="إنشاء حساب"
      intro={<p>أنشئ حساباً في موقع الجائزة. إنشاء الحساب لا يعني التقدّم إلى الجائزة أو الترشّح لها.</p>}
    >
      {alert && (
        <FormAlert tone="error" id={ALERT_ID}>
          <p>{alert}</p>
        </FormAlert>
      )}

      <form className="auth-form" onSubmit={onSubmit} noValidate>
        <TextField id="name" label="الاسم الكامل" autoComplete="name" value={name} onChange={setName} error={fieldError('name')} required />
        <TextField
          id="email"
          label="البريد الإلكتروني"
          type="email"
          autoComplete="email"
          value={email}
          onChange={setEmail}
          error={fieldError('email')}
          hint="سنرسل إليه رابطاً لتأكيده."
          required
        />
        <PasswordField
          id="password"
          label="كلمة المرور"
          autoComplete="new-password"
          value={password}
          onChange={setPassword}
          error={fieldError('password')}
          hint={PASSWORD_HINT}
          required
        />
        <PasswordField
          id="password_confirmation"
          label="تأكيد كلمة المرور"
          autoComplete="new-password"
          value={confirmation}
          onChange={setConfirmation}
          error={fieldError('password_confirmation')}
          required
        />
        <SubmitButton busy={busy} busyLabel="جارٍ إنشاء الحساب…">
          إنشاء الحساب
        </SubmitButton>
      </form>

      <Divider>أو</Divider>
      <GoogleButton available={googleAvailable} />

      <p className="auth-panel__switch">
        لديك حساب؟ <Link href="/login">تسجيل الدخول</Link>
      </p>
    </AuthLayout>
  )
}
