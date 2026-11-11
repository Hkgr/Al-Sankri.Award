import arGreen400 from '../assets/brand/official/logo-ar-green-400.webp'
import arGreen800 from '../assets/brand/official/logo-ar-green-800.webp'
import arWhite400 from '../assets/brand/official/logo-ar-white-400.webp'
import arWhite800 from '../assets/brand/official/logo-ar-white-800.webp'
import enGreen400 from '../assets/brand/official/logo-en-green-400.webp'
import enGreen800 from '../assets/brand/official/logo-en-green-800.webp'
import enWhite400 from '../assets/brand/official/logo-en-white-400.webp'
import enWhite800 from '../assets/brand/official/logo-en-white-800.webp'
import partnerRowad from '../assets/brand/official/partner-rowad-425.webp'
import partnerCultureDirectorate from '../assets/brand/official/partner-aleppo-culture-directorate-188.webp'
import partnerUniversity from '../assets/brand/official/partner-university-of-aleppo-160.webp'
import culture400 from '../assets/brand/official/trophy-culture-and-arts-400.webp'
import culture800 from '../assets/brand/official/trophy-culture-and-arts-800.webp'
import heritage400 from '../assets/brand/official/trophy-heritage-400.webp'
import heritage800 from '../assets/brand/official/trophy-heritage-800.webp'
import creativity400 from '../assets/brand/official/trophy-creativity-400.webp'
import creativity800 from '../assets/brand/official/trophy-creativity-800.webp'

const logo = (small: string, large: string) => ({ src: small, srcSet: `${small} 400w, ${large} 800w`, width: 800, height: 259 })

/** Independent official color variants, resized only; white is never synthesized from green. */
export const brandLogos = {
  ar: { light: logo(arGreen400, arGreen800), dark: logo(arWhite400, arWhite800) },
  en: { light: logo(enGreen400, enGreen800), dark: logo(enWhite400, enWhite800) },
}

/** The 800px official logos offered for download in the Media Center, unchanged. */
export const brandLogoDownloads = [
  { locale: 'ar', surface: 'light', src: arGreen800, file: 'sankari-award-logo-ar-green.webp' },
  { locale: 'ar', surface: 'dark', src: arWhite800, file: 'sankari-award-logo-ar-white.webp' },
  { locale: 'en', surface: 'light', src: enGreen800, file: 'sankari-award-logo-en-green.webp' },
  { locale: 'en', surface: 'dark', src: enWhite800, file: 'sankari-award-logo-en-white.webp' },
] as const

/** Intrinsic size of the 800px derivative; the vertical artworks differ slightly in proportion. */
const trophy = (small: string, large: string, height: number) => ({
  fallback: small,
  sources: [{ srcSet: `${small} 400w, ${large} 800w`, type: 'image/webp' }],
  width: 800,
  height,
})

/** The owner's vertical trophy artwork on white (supplied 2026-11-11), resized only. */
export const domainTrophies = {
  'culture-and-arts': trophy(culture400, culture800, 919),
  heritage: trophy(heritage400, heritage800, 925),
  creativity: trophy(creativity400, creativity800, 906),
}

/**
 * The partner organizations' supplied logos (2026-11-11): transparent margin removed,
 * 160px high for display at up to 80 CSS px; colors and proportions untouched.
 */
export const partnerLogos = {
  rowad: { src: partnerRowad, width: 425, height: 160 },
  'aleppo-culture-directorate': { src: partnerCultureDirectorate, width: 188, height: 160 },
  'university-of-aleppo': { src: partnerUniversity, width: 160, height: 160 },
}
