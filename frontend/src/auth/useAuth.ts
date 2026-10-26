import { createContext, useContext } from 'react'

/** The account fields the backend exposes to the browser (UserResource). */
export type User = {
  name: string
  email: string
  email_verified: boolean
  has_password: boolean
  google_linked: boolean
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
