import { createContext, useContext } from 'react'
import type { Locale } from './messages'

export type LocaleValue = {
  locale: Locale
  setLocale: (locale: Locale) => void
  t: (key: string, vars?: Record<string, string | number>) => string
  dir: 'rtl' | 'ltr'
}

export const LocaleContext = createContext<LocaleValue | null>(null)

export function useLocale(): LocaleValue {
  const value = useContext(LocaleContext)
  if (!value) throw new Error('useLocale must be used within LocaleProvider')
  return value
}
