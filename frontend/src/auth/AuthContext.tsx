import { useCallback, useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import { ApiError, api } from '../lib/api'
import { AuthContext } from './useAuth'
import type { User } from './useAuth'

type AuthOptions = {
  google: { available: boolean }
}

async function fetchUser(): Promise<User | null> {
  try {
    return (await api.get<{ data: User }>('/api/user')).data
  } catch (error) {
    if (error instanceof ApiError && (error.status === 401 || error.status === 403)) return null
    throw error
  }
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null | undefined>(undefined)
  const [googleAvailable, setGoogleAvailable] = useState(false)

  useEffect(() => {
    let active = true
    // A failed check (offline, server error) is treated as signed out; the
    // forms report network problems themselves when the visitor acts.
    fetchUser()
      .catch(() => null)
      .then((current) => active && setUser(current))
    api
      .get<AuthOptions>('/api/auth/options')
      .then((options) => active && setGoogleAvailable(options.google.available))
      .catch(() => active && setGoogleAvailable(false))
    return () => {
      active = false
    }
  }, [])

  const refreshUser = useCallback(async () => {
    setUser(await fetchUser())
  }, [])

  const logout = useCallback(async () => {
    try {
      await api.post('/api/auth/logout')
    } catch (error) {
      // An already-expired session is a successful logout.
      if (!(error instanceof ApiError && error.status === 401)) throw error
    }
    setUser(null)
  }, [])

  const value = useMemo(
    () => ({ user, googleAvailable, setUser, refreshUser, logout }),
    [user, googleAvailable, refreshUser, logout],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
