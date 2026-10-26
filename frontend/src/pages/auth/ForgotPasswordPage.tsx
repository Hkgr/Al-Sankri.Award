import { useState } from 'react'
import type { FormEvent } from 'react'
import { FormAlert, SubmitButton, TextField } from '../../components/forms/FormControls'
import { api } from '../../lib/api'
import { Link } from '../../lib/router'
import { AuthLayout } from './AuthLayout'
import { ALERT_ID, useSubmission } from './useSubmission'

export function ForgotPasswordPage() {
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
    <AuthLayout
      title="استعادة كلمة المرور"
      intro={<p>أدخل البريد الإلكتروني لحسابك، وسنرسل إليه رابطاً لتعيين كلمة مرور جديدة.</p>}
    >
      {alert && (
        <FormAlert tone="error" id={ALERT_ID}>
          <p>{alert}</p>
        </FormAlert>
      )}
      {sent && (
        <FormAlert tone="success">
          <p>{sent}</p>
          <p>لم تصلك الرسالة؟ تحقّق من مجلد الرسائل غير المرغوب فيها، أو أعد الطلب بعد دقيقة.</p>
        </FormAlert>
      )}

      <form className="auth-form" onSubmit={onSubmit} noValidate>
        <TextField
          id="email"
          label="البريد الإلكتروني"
          type="email"
          autoComplete="email"
          value={email}
          onChange={setEmail}
          error={fieldError('email')}
          required
        />
        <SubmitButton busy={busy} busyLabel="جارٍ الإرسال…">
          إرسال رابط الاستعادة
        </SubmitButton>
      </form>

      <p className="auth-panel__switch">
        <Link href="/login">العودة إلى تسجيل الدخول</Link>
      </p>
    </AuthLayout>
  )
}
