/**
 * The short outcome codes the backend puts in the query string after
 * redirects (Google sign-in, linking, verification, expired sessions), as
 * a tone and a message key for the current language.
 */

export type Notice = { tone: 'error' | 'success' | 'info'; key: string }

const googleSignIn: Record<string, Notice['tone']> = {
  unavailable: 'info',
  cancelled: 'info',
  invalid_state: 'error',
  failed: 'error',
  throttled: 'error',
  account_exists: 'info',
  disabled: 'error',
  confirm_email: 'info',
  confirm_invalid: 'error',
  email_unverified: 'error',
}

const googleLink: Record<string, Notice['tone']> = {
  linked: 'success',
  already_linked: 'info',
  owned_by_other: 'error',
  user_has_other: 'error',
  link_expired: 'error',
  email_unverified: 'error',
  unavailable: 'info',
  cancelled: 'info',
  invalid_state: 'error',
  failed: 'error',
  throttled: 'error',
}

export function loginNotice(params: URLSearchParams): Notice | null {
  const google = params.get('google')
  if (google && googleSignIn[google]) return { tone: googleSignIn[google], key: `notice.google.${google}` }
  if (params.get('account') === 'disabled') return { tone: 'error', key: 'notice.google.disabled' }
  if (params.get('session') === 'expired') return { tone: 'info', key: 'notice.session' }
  if (params.get('reset') === 'success') return { tone: 'success', key: 'notice.reset' }
  return null
}

export function accountNotice(params: URLSearchParams): Notice | null {
  const google = params.get('google')
  return google && googleLink[google] ? { tone: googleLink[google], key: `notice.link.${google}` } : null
}

export const verificationResults: Record<string, Notice> = {
  verified: { tone: 'success', key: 'notice.verify.verified' },
  already: { tone: 'info', key: 'notice.verify.already' },
  invalid: { tone: 'error', key: 'notice.verify.invalid' },
  mismatch: { tone: 'error', key: 'notice.verify.mismatch' },
}
