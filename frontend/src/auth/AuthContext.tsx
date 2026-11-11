import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import type { ReactNode } from 'react'
import { ApiError, api } from '../lib/api'
import { AuthContext } from './useAuth'
import type { RefreshOptions, RefreshResult, User } from './useAuth'
import { useLocale } from '../i18n/useLocale'

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
  const [user, setUserState] = useState<User | null | undefined>(undefined)
  const { setLocale } = useLocale()
  const setUser = useCallback((next: User | null) => {
    setUserState(next)
    if (next?.preferred_locale) setLocale(next.preferred_locale)
  }, [setLocale])
  const [googleAvailable, setGoogleAvailable] = useState(false)
  // The account shown now, for refreshUser({ keepAccount: true }).
  const current = useRef(user)
  useEffect(() => {
    current.current = user
  }, [user])

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
  }, [setUser])

  const refreshUser = useCallback(async (options: RefreshOptions = {}): Promise<RefreshResult> => {
    const fresh = await fetchUser()
    const shown = current.current
    if (options.keepAccount && shown && fresh?.account_context !== shown.account_context) return 'account_changed'
    setUser(fresh)
    return 'updated'
  }, [setUser])

  useEffect(() => {
    const refresh = () => {
      if (document.visibilityState === 'visible' && current.current) {
        void refreshUser({ keepAccount: true }).catch(() => undefined)
      }
    }
    window.addEventListener('focus', refresh)
    document.addEventListener('visibilitychange', refresh)
    return () => {
      window.removeEventListener('focus', refresh)
      document.removeEventListener('visibilitychange', refresh)
    }
  }, [refreshUser])

  const logout = useCallback(async () => {
    try {
      await api.post('/api/auth/logout')
    } catch (error) {
      // An already-expired session is a successful logout.
      if (!(error instanceof ApiError && error.status === 401)) throw error
    }
    setUser(null)
  }, [setUser])

  const value = useMemo(
    () => ({ user, googleAvailable, setUser, refreshUser, logout }),
    [user, googleAvailable, refreshUser, logout, setUser],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
