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
import { loginNotice } from './notices'
import { ALERT_ID, useSubmission } from './useSubmission'

/** Where to go after a successful sign-in: client-side for app pages. */
function useFollowRedirect() {
  const { navigate } = useRouter()
  return (target: string) => {
    if (target === '/account' || target.startsWith('/account?')) navigate(target, { replace: true })
    else window.location.assign(target)
  }
}

export function LoginPage() {
  const { location } = useRouter()
  const { setUser, googleAvailable } = useAuth()
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
    <AuthLayout title="تسجيل الدخول" intro={<p>ادخل إلى حسابك في موقع الجائزة.</p>}>
      {alert ? (
        <FormAlert tone="error" id={ALERT_ID}>
          <p>{alert}</p>
        </FormAlert>
      ) : (
        notice && (
          <FormAlert tone={notice.tone}>
            <p>{notice.text}</p>
          </FormAlert>
        )
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
          label="كلمة المرور"
          autoComplete="current-password"
          value={password}
          onChange={setPassword}
          error={fieldError('password')}
          required
        />
        <p className="auth-form__aside">
          <Link href="/forgot-password">نسيت كلمة المرور؟</Link>
        </p>
        <SubmitButton busy={busy} busyLabel="جارٍ تسجيل الدخول…">
          تسجيل الدخول
        </SubmitButton>
      </form>

      <Divider>أو</Divider>
      <GoogleButton available={googleAvailable} redirect={requested} />

      <p className="auth-panel__switch">
        ليس لديك حساب؟ <Link href="/register">إنشاء حساب جديد</Link>
      </p>
    </AuthLayout>
  )
}
