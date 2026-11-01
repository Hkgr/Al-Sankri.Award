import { useLocale } from '../i18n/useLocale'
import { Link } from '../lib/router'

export type Crumb = { href: string; label: string }

/** The trail above an inner page's title; the last item is the current page. */
export function Breadcrumbs({ trail }: { trail: Crumb[] }) {
  const { t } = useLocale()

  return (
    <nav className="breadcrumbs" aria-label={t('nav.breadcrumbs')}>
      <ol className="breadcrumbs__list">
        {trail.map((crumb, index) => (
          <li key={crumb.href} className="breadcrumbs__item">
            {index === trail.length - 1 ? <span aria-current="page">{crumb.label}</span> : <Link href={crumb.href}>{crumb.label}</Link>}
          </li>
        ))}
      </ol>
    </nav>
  )
}
