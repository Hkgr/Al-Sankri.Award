import googleG from '../assets/google/google-g.svg'

/**
 * "Continue with Google", built to Google's sign-in branding guidelines
 * (light theme): white fill, #747775 1px border, #1F1F1F label, and the
 * official "G" taken unaltered from Google's signin-assets package (see
 * docs/platform/authentication.md). It is a plain link: the backend runs the
 * whole OAuth flow, and the browser never sees credentials or tokens.
 */
export function GoogleButton({ available, redirect }: { available: boolean; redirect?: string }) {
  const label = 'المتابعة مع Google'

  if (!available) {
    return (
      <div className="google-signin">
        <button type="button" className="google-button" disabled aria-describedby="google-unavailable">
          <img className="google-button__logo" src={googleG} alt="" width={20} height={20} />
          <span className="google-button__label">{label}</span>
        </button>
        <p id="google-unavailable" className="google-signin__note">
          تسجيل الدخول عبر Google غير متاح مؤقتاً. يمكنك استخدام البريد الإلكتروني وكلمة المرور.
        </p>
      </div>
    )
  }

  const href = redirect ? `/auth/google/redirect?redirect=${encodeURIComponent(redirect)}` : '/auth/google/redirect'

  return (
    <div className="google-signin">
      <a className="google-button" href={href}>
        <img className="google-button__logo" src={googleG} alt="" width={20} height={20} />
        <span className="google-button__label">{label}</span>
      </a>
    </div>
  )
}
