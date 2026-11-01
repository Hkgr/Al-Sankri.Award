import { useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import { setLocalizer } from '../lib/api'
import { localeDir, localizeServerData, setCurrentLocale, translate, tr, type Locale } from './messages'
import { LocaleContext, type LocaleValue } from './useLocale'

const STORAGE_KEY = 'sa-locale'

// The API client stays free of imports; it translates through these.
setLocalizer({ text: (value) => tr(value), data: localizeServerData })

function readStored(): Locale {
  try {
    const value = window.localStorage.getItem(STORAGE_KEY)
    if (value === 'en' || value === 'ar') return value
  } catch {
    // Keep the default Arabic UI when storage is unavailable.
  }
  return 'ar'
}

export function LocaleProvider({ children }: { children: ReactNode }) {
  const [locale, setLocaleState] = useState<Locale>(readStored)
  // Before the children render, so helpers outside components (tr) see the same language.
  setCurrentLocale(locale)

  useEffect(() => {
    document.documentElement.lang = locale
    document.documentElement.dir = localeDir(locale)
    try {
      window.localStorage.setItem(STORAGE_KEY, locale)
    } catch {
      // Selection still applies for this session.
    }
  }, [locale])

  const value = useMemo<LocaleValue>(
    () => ({
      locale,
      setLocale: setLocaleState,
      t: (key, vars) => translate(locale, key, vars),
      dir: localeDir(locale),
    }),
    [locale],
  )

  return <LocaleContext.Provider value={value}>{children}</LocaleContext.Provider>
}
