import { domainTrophies } from '../content/brandAssets'
import type { MainDomain } from '../content/site'
import { Photo } from './Photo'

/**
 * The domain's official vertical trophy artwork, decorative beside the domain heading.
 * Shown whole on a white surface: never cropped, padded or stretched (object-fit: contain).
 */
export function DomainTrophy({ domain, className, sizes }: { domain: MainDomain['slug']; className: string; sizes: string }) {
  return <Photo {...domainTrophies[domain]} className={`domain-trophy ${className}`} alt="" sizes={sizes} loading="lazy" />
}
