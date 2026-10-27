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
}

/** Where a signed-in account belongs: the candidate dashboard or the account page. */
export function homeFor(user: Pick<User, 'is_candidate'>): string {
  return user.is_candidate ? '/dashboard' : '/account'
}

export type AuthValue = {
  /** undefined while the session is being checked, null when signed out. */
  user: User | null | undefined
  googleAvailable: boolean
  setUser: (user: User | null) => void
  refreshUser: () => Promise<void>
  logout: () => Promise<void>
}

export const AuthContext = createContext<AuthValue | null>(null)

export function useAuth(): AuthValue {
  const auth = useContext(AuthContext)
  if (!auth) throw new Error('useAuth must be used inside AuthProvider')
  return auth
}
