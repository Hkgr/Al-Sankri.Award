import { useLocale } from '../i18n/useLocale'
import { siteAr } from './site'
import type { SiteContent } from './site'
import { siteEn } from './siteEn'

/** The public website copy in the visitor's language. */
export function useSite(): SiteContent {
  const { locale } = useLocale()

  return locale === 'en' ? siteEn : siteAr
}
