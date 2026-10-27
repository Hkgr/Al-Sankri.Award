import { useState } from 'react'
import { useAuth } from '../../auth/useAuth'
import { FormAlert } from '../../components/forms/FormControls'
import { api, errorMessage } from '../../lib/api'
import { useAccountFailure } from './useCandidateRequests'

/**
 * Shown while the account's email is unverified: the dashboard stays usable,
 * but confirming the basic information needs a verified email. Offers the
 * existing resend action and a re-check after the link was opened elsewhere.
 */
export function VerifyEmailNotice({ context }: { context: 'dashboard' | 'form' }) {
  const { refreshUser } = useAuth()
  const handleAccountFailure = useAccountFailure()
  const [busy, setBusy] = useState<'resend' | 'check' | null>(null)
  const [result, setResult] = useState<{ tone: 'success' | 'error' | 'info'; text: string } | null>(null)

  const resend = async () => {
    if (busy) return
    setBusy('resend')
    setResult(null)
    try {
      const response = await api.post<{ message: string }>('/api/email/verification-notification')
      setResult({ tone: 'success', text: response.message })
    } catch (error) {
      if (!handleAccountFailure(error)) setResult({ tone: 'error', text: errorMessage(error) })
    } finally {
      setBusy(null)
    }
  }

  const recheck = async () => {
    if (busy) return
    setBusy('check')
    setResult(null)
    try {
      // In the form, never move unsaved input to another account signed in meanwhile.
      const outcome = await refreshUser({ keepAccount: context === 'form' })
      setResult(
        outcome === 'account_changed'
          ? { tone: 'error', text: 'تغيّر الحساب المسجّل دخوله في هذا المتصفح، فلم نحدّث هذه الصفحة حتى لا يضيع ما أدخلته أو يُنسب إلى حساب آخر.' }
          : // Still shown means still unverified.
            { tone: 'info', text: 'لم يُؤكَّد البريد الإلكتروني بعد. افتح الرابط الذي أرسلناه إليه، ثم أعد التحقق.' },
      )
    } catch (error) {
      setResult({ tone: 'error', text: errorMessage(error) })
    } finally {
      setBusy(null)
    }
  }

  return (
    <section className="candidate-notice" aria-labelledby={`verify-${context}-title`}>
      <h2 id={`verify-${context}-title`} className="candidate-notice__title">
        أكّد بريدك الإلكتروني
      </h2>
      <p>
        {context === 'dashboard'
          ? 'يمكنك استخدام لوحة المترشح الآن، لكن حفظ البيانات الأساسية يتطلّب تأكيد البريد الإلكتروني أولاً. افتح الرابط الذي أرسلناه إلى بريدك.'
          : 'لن تتمكن من تأكيد حفظ البيانات قبل تأكيد بريدك الإلكتروني. يمكنك تعبئة النموذج الآن، ثم تأكيد البريد قبل الحفظ؛ يبقى ما أدخلته في هذه الصفحة.'}
      </p>
      {result && (
        <FormAlert tone={result.tone}>
          <p>{result.text}</p>
        </FormAlert>
      )}
      <div className="candidate-actions">
        <button type="button" className="button button--secondary" onClick={resend} aria-busy={busy === 'resend' || undefined} aria-disabled={busy !== null || undefined}>
          {busy === 'resend' ? 'جارٍ الإرسال…' : 'إرسال رابط تأكيد جديد'}
        </button>
        <button type="button" className="button button--secondary" onClick={recheck} aria-busy={busy === 'check' || undefined} aria-disabled={busy !== null || undefined}>
          {busy === 'check' ? 'جارٍ التحقق…' : 'أكّدت بريدي، أعد التحقق'}
        </button>
      </div>
    </section>
  )
}
