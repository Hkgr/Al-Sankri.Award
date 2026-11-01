import { useLocale } from './useLocale'
import type { Locale } from './messages'

/**
 * Compact Arabic / English switch for headers across the site.
 */
export function LanguageToggle({ className = '' }: { className?: string }) {
  const { locale, setLocale, t } = useLocale()

  const choose = (next: Locale) => {
    if (next !== locale) setLocale(next)
  }

  return (
    <div className={`lang-toggle ${className}`.trim()} role="group" aria-label={t('lang.switch')}>
      <button type="button" className="lang-toggle__btn" aria-pressed={locale === 'ar'} onClick={() => choose('ar')}>
        {t('lang.ar')}
      </button>
      <span className="lang-toggle__sep" aria-hidden="true">
        |
      </span>
      <button type="button" className="lang-toggle__btn" aria-pressed={locale === 'en'} lang="en" dir="ltr" onClick={() => choose('en')}>
        {t('lang.en')}
      </button>
    </div>
  )
}
