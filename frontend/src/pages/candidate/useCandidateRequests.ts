import { useCallback } from 'react'
import { useAuth } from '../../auth/useAuth'
import { ApiError } from '../../lib/api'
import { useRouter } from '../../lib/useRouter'

/**
 * Shared handling of the candidate endpoints' account failures, as on the
 * account page: an ended session (401) or a disabled account goes to the
 * sign-in page, and a lost classification (not_candidate) reloads the
 * account. A page that still showed another account than the one signed in
 * now (account_mismatch) reloads the account snapshot, so the page is shown
 * again for the current account, coherently. Returns true when the error was
 * handled.
 *
 * Forms with unsaved input do not use this for their submissions: they keep
 * the input and explain how to continue instead (see BasicInformationForm).
 */
export function useAccountFailure() {
  const { setUser, refreshUser } = useAuth()
  const { location, navigate } = useRouter()

  return useCallback(
    (error: unknown): boolean => {
      if (!(error instanceof ApiError)) return false
      if (error.status === 401) {
        setUser(null)
        navigate(`/login?session=expired&redirect=${encodeURIComponent(location.pathname)}`, { replace: true })
        return true
      }
      if (error.code === 'account_disabled') {
        setUser(null)
        navigate('/login?account=disabled', { replace: true })
        return true
      }
      if (error.code === 'not_candidate') {
        void refreshUser().catch(() => undefined)
        return true
      }
      if (error.code === 'account_mismatch') {
        void refreshUser()
          .then(() => navigate(`${location.pathname}?account=changed`, { replace: true }))
          .catch(() => undefined)
        return true
      }
      return false
    },
    [setUser, refreshUser, navigate, location.pathname],
  )
}
