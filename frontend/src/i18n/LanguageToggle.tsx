import { useLocale } from './useLocale'
import type { Locale } from './messages'
import { useState } from 'react'
import { useAuth } from '../auth/useAuth'
import { api } from '../lib/api'

/**
 * Compact Arabic / English switch for headers across the site.
 */
export function LanguageToggle({ className = '' }: { className?: string }) {
  const { locale, setLocale, t } = useLocale()
  const { user, setUser } = useAuth()
  const [saving, setSaving] = useState(false)
  const [failed, setFailed] = useState(false)

  const choose = async (next: Locale) => {
    if (next !== locale) setLocale(next)
    if (!user || saving || user.preferred_locale === next) return
    setSaving(true)
    setFailed(false)
    try {
      await api.post('/api/account/language', { locale: next, account_context: user.account_context })
      setUser({ ...user, preferred_locale: next })
    } catch {
      setFailed(true)
    } finally { setSaving(false) }
  }

  return (
    <div className={`lang-toggle ${className}`.trim()} role="group" aria-label={t('lang.switch')}>
      <button type="button" className="lang-toggle__btn" aria-pressed={locale === 'ar'} disabled={saving} onClick={() => void choose('ar')}>
        {t('lang.ar')}
      </button>
      <span className="lang-toggle__sep" aria-hidden="true">
        |
      </span>
      <button type="button" className="lang-toggle__btn" aria-pressed={locale === 'en'} disabled={saving} lang="en" dir="ltr" onClick={() => void choose('en')}>
        {t('lang.en')}
      </button>
      {failed && <span role="status">{t('progress.languageError')}</span>}
    </div>
  )
}
