import { useEffect } from 'react'
import type { ReactNode } from 'react'
import { homeFor, useAuth } from './auth/useAuth'
import { SiteFooter } from './components/layout/SiteFooter'
import { SiteHeader } from './components/layout/SiteHeader'
import { Link } from './lib/router'
import { useRouter } from './lib/useRouter'
import { AccountPage } from './pages/auth/AccountPage'
import { AuthLayout } from './pages/auth/AuthLayout'
import { ForgotPasswordPage } from './pages/auth/ForgotPasswordPage'
import { LoginPage } from './pages/auth/LoginPage'
import { RegisterPage } from './pages/auth/RegisterPage'
import { ResetPasswordPage } from './pages/auth/ResetPasswordPage'
import { VerifyEmailPage } from './pages/auth/VerifyEmailPage'
import { CandidateApp } from './pages/candidate/CandidateApp'
import { HomePage } from './pages/home/HomePage'
import { PolicyPage } from './pages/policies/PolicyPage'

const HOME_TITLE = document.title

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

  useEffect(() => {
    if (location.pathname === '/') document.title = HOME_TITLE
  }, [location.pathname])

  switch (location.pathname) {
    case '/':
      return <HomePage />
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

  // The candidate area has its own layout (CandidateLayout).
  if (['/dashboard', '/dashboard/basic-information', '/dashboard/nomination-details', '/dashboard/documentation', '/dashboard/review'].includes(location.pathname)) return <CandidateApp />

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
