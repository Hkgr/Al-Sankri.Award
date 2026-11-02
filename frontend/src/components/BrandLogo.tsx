import { brandLogos } from '../content/brandAssets'
import { useLocale } from '../i18n/useLocale'

/** The current language's official horizontal logo on the specified surrounding surface. */
export function BrandLogo({ surface, alt, className = '', sizes = '240px', loading = 'eager' }: {
  surface: 'light' | 'dark'
  alt: string
  className?: string
  sizes?: string
  loading?: 'eager' | 'lazy'
}) {
  const { locale } = useLocale()
  const artwork = brandLogos[locale][surface]

  return <img {...artwork} className={`award-logo ${className}`.trim()} alt={alt} sizes={sizes} loading={loading} decoding="async" />
}
