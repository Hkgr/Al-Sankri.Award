import { createContext, useContext } from 'react'

/** The account fields the backend exposes to the browser (UserResource). */
export type User = {
  name: string
  email: string
  email_verified: boolean
  has_password: boolean
  google_linked: boolean
  /** Holds the «مترشح» classification (assigned by the backend only). */
  is_candidate: boolean
  /**
   * Opaque value identifying this account snapshot. Candidate pages send it
   * back, so the server refuses requests made while the page shows another
   * account than the one signed in.
   */
  account_context: string
}

/**
 * keepAccount: only accept a refreshed account if it is still the same one
 * (an open form with unsaved input must not be moved to another account).
 */
export type RefreshOptions = { keepAccount?: boolean }

/** 'account_changed' when keepAccount found another account signed in (nothing was updated). */
export type RefreshResult = 'updated' | 'account_changed'

/** Where a signed-in account belongs: the candidate dashboard or the account page. */
export function homeFor(user: Pick<User, 'is_candidate'>): string {
  return user.is_candidate ? '/dashboard' : '/account'
}

export type AuthValue = {
  /** undefined while the session is being checked, null when signed out. */
  user: User | null | undefined
  googleAvailable: boolean
  setUser: (user: User | null) => void
  refreshUser: (options?: RefreshOptions) => Promise<RefreshResult>
  logout: () => Promise<void>
}

export const AuthContext = createContext<AuthValue | null>(null)

export function useAuth(): AuthValue {
  const auth = useContext(AuthContext)
  if (!auth) throw new Error('useAuth must be used inside AuthProvider')
  return auth
}
