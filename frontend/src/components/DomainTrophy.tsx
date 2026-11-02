import { domainTrophies } from '../content/brandAssets'
import type { MainDomain } from '../content/site'
import { Photo } from './Photo'

/** Decorative beside the domain heading. Keep the entire supplied image, without a cutout. */
export function DomainTrophy({ domain, className, sizes }: { domain: MainDomain['slug']; className: string; sizes: string }) {
  return <Photo {...domainTrophies[domain]} className={className} alt="" sizes={sizes} />
}
