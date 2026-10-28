import { homeFor, useAuth } from '../../auth/useAuth'
import { FormAlert } from '../../components/forms/FormControls'
import { Link } from '../../lib/router'
import { useSearchParam } from '../../lib/useRouter'
import { AuthLayout } from './AuthLayout'
import { verificationResults } from './notices'

/** Where the link in the verification email lands, via the backend. */
export function VerifyEmailPage() {
  const status = useSearchParam('status')
  const { user } = useAuth()
  const result = status ? verificationResults[status] : undefined

  return (
    <AuthLayout title="تأكيد البريد الإلكتروني">
      {result ? (
        <FormAlert tone={result.tone}>
          <p>{result.text}</p>
        </FormAlert>
      ) : (
        <p className="auth-panel__text">
          لتأكيد بريدك الإلكتروني، افتح الرابط الذي أرسلناه إليه عند إنشاء الحساب. يمكنك طلب رابط جديد من صفحة «حسابي».
        </p>
      )}
      <p className="auth-panel__switch">
        {user ? (
          <Link href={homeFor(user)}>
            {homeFor(user) === '/admin' ? 'الانتقال إلى لوحة الإدارة' : homeFor(user) === '/dashboard' ? 'الانتقال إلى لوحة المترشح' : 'الانتقال إلى حسابي'}
          </Link>
        ) : (
          <Link href="/login">تسجيل الدخول</Link>
        )}
      </p>
    </AuthLayout>
  )
}
