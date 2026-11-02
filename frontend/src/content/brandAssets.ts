import arGreen400 from '../assets/brand/official/logo-ar-green-400.webp'
import arGreen800 from '../assets/brand/official/logo-ar-green-800.webp'
import arWhite400 from '../assets/brand/official/logo-ar-white-400.webp'
import arWhite800 from '../assets/brand/official/logo-ar-white-800.webp'
import enGreen400 from '../assets/brand/official/logo-en-green-400.webp'
import enGreen800 from '../assets/brand/official/logo-en-green-800.webp'
import enWhite400 from '../assets/brand/official/logo-en-white-400.webp'
import enWhite800 from '../assets/brand/official/logo-en-white-800.webp'
import culture480 from '../assets/brand/official/trophy-culture-and-arts-480.webp'
import culture960 from '../assets/brand/official/trophy-culture-and-arts-960.webp'
import heritage480 from '../assets/brand/official/trophy-heritage-480.webp'
import heritage960 from '../assets/brand/official/trophy-heritage-960.webp'
import creativity480 from '../assets/brand/official/trophy-creativity-480.webp'
import creativity960 from '../assets/brand/official/trophy-creativity-960.webp'

const logo = (small: string, large: string) => ({ src: small, srcSet: `${small} 400w, ${large} 800w`, width: 800, height: 259 })

/** Independent official color variants, resized only; white is never synthesized from green. */
export const brandLogos = {
  ar: { light: logo(arGreen400, arGreen800), dark: logo(arWhite400, arWhite800) },
  en: { light: logo(enGreen400, enGreen800), dark: logo(enWhite400, enWhite800) },
}

const trophy = (small: string, large: string) => ({
  fallback: small,
  sources: [{ srcSet: `${small} 480w, ${large} 960w`, type: 'image/webp' }],
  width: 960,
  height: 679,
})

/** Complete photographs, including their supplied opaque patterned backgrounds. */
export const domainTrophies = {
  'culture-and-arts': trophy(culture480, culture960),
  heritage: trophy(heritage480, heritage960),
  creativity: trophy(creativity480, creativity960),
}
