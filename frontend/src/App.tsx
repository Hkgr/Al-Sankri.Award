import { useEffect } from 'react'
import type { ReactNode } from 'react'
import { homeFor, useAuth } from './auth/useAuth'
import { SiteFooter } from './components/layout/SiteFooter'
import { SiteHeader } from './components/layout/SiteHeader'
import { Link } from './lib/router'
import { useRouter } from './lib/useRouter'
import { AdminApp } from './pages/admin/AdminApp'
import { isAdminPath } from './pages/admin/adminPaths'
import { AccountPage } from './pages/auth/AccountPage'
import { AuthLayout } from './pages/auth/AuthLayout'
import { ForgotPasswordPage } from './pages/auth/ForgotPasswordPage'
import { LoginPage } from './pages/auth/LoginPage'
import { RegisterPage } from './pages/auth/RegisterPage'
import { ResetPasswordPage } from './pages/auth/ResetPasswordPage'
import { VerifyEmailPage } from './pages/auth/VerifyEmailPage'
import { CandidateApp } from './pages/candidate/CandidateApp'
import { JudgeApp } from './pages/judge/JudgeApp'
import { isJudgePath } from './pages/judge/judgePaths'
import { HomePage } from './pages/home/HomePage'
import { PolicyPage } from './pages/policies/PolicyPage'
import { AboutPage } from './pages/public/AboutPage'
import { EligibilityDomainPage, EligibilityPage } from './pages/public/EligibilityPage'
import { JourneyPage } from './pages/public/JourneyPage'
import { domainBySlug } from './content/site'

/** Sign-in and sign-up pages send visitors who are already signed in to their dashboard or account. */
function GuestOnly({ children }: { children: ReactNode }) {
  const { user } = useAuth()
  const { navigate } = useRouter()

  useEffect(() => {
    if (user) navigate(homeFor(user), { replace: true })
  }, [user, navigate])

  return user ? null : children
}

function NotFoundPage() {
  return (
    <AuthLayout title="الصفحة غير موجودة">
      <p className="auth-panel__text">لم نجد الصفحة التي طلبتها.</p>
      <p className="auth-panel__switch">
        <Link href="/">العودة إلى الصفحة الرئيسية</Link>
      </p>
    </AuthLayout>
  )
}

function CurrentPage() {
  const { location } = useRouter()

  const domainPage = /^\/eligibility\/([a-z-]+)$/.exec(location.pathname)
  if (domainPage) {
    const domain = domainBySlug(domainPage[1])
    return domain ? <EligibilityDomainPage key={domain.slug} domain={domain} /> : <NotFoundPage />
  }

  switch (location.pathname) {
    case '/':
      return <HomePage />
    case '/about':
      return <AboutPage />
    case '/journey':
      return <JourneyPage />
    case '/eligibility':
      return <EligibilityPage />
    case '/login':
      return (
        <GuestOnly>
          <LoginPage />
        </GuestOnly>
      )
    case '/register':
      return (
        <GuestOnly>
          <RegisterPage />
        </GuestOnly>
      )
    case '/forgot-password':
      return <ForgotPasswordPage />
    case '/reset-password':
      return <ResetPasswordPage />
    case '/verify-email':
      return <VerifyEmailPage />
    case '/account':
      return <AccountPage />
    case '/policies/privacy-policy':
      return <PolicyPage code="privacy_policy" slug="privacy-policy" />
    case '/policies/terms-of-use':
      return <PolicyPage code="terms_of_use" slug="terms-of-use" />
    default:
      return <NotFoundPage />
  }
}

function App() {
  const { location } = useRouter()

  // The candidate and administration areas have their own layouts.
  if (['/dashboard', '/dashboard/basic-information', '/dashboard/nomination-details', '/dashboard/documentation', '/dashboard/review'].includes(location.pathname)) return <CandidateApp />
  if (isAdminPath(location.pathname)) return <AdminApp />
  if (isJudgePath(location.pathname)) return <JudgeApp />

  return (
    <>
      <SiteHeader />
      <main id="main" tabIndex={-1}>
        <CurrentPage />
      </main>
      <SiteFooter />
    </>
  )
}

export default App
