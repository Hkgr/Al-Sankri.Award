import type { ReactNode } from 'react'
import { Breadcrumbs } from '../../components/Breadcrumbs'
import type { Crumb } from '../../components/Breadcrumbs'

/** The opening of an inner page: breadcrumbs, title and introduction, on the light patterned band. */
export function PageHeader({ trail, eyebrow, title, children }: { trail: Crumb[]; eyebrow?: string; title: string; children?: ReactNode }) {
  return (
    <header className="page-header">
      <div className="container page-header__inner">
        <Breadcrumbs trail={trail} />
        {eyebrow && <p className="eyebrow">{eyebrow}</p>}
        <h1 className="page-header__title">{title}</h1>
        {children && <div className="page-header__intro">{children}</div>}
      </div>
    </header>
  )
}
