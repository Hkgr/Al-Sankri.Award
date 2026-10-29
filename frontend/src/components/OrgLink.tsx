import { Fragment } from 'react'
import { organizations } from '../content/site'
import type { OrganizationKey, RichText as RichTextValue } from '../content/site'

/**
 * An organisation's name as a link to its own website, in a new tab
 * (announced to assistive technology). HAND keeps its Latin letters
 * isolated from the Arabic text around it.
 */
export function OrgLink({ org, className, label }: { org: OrganizationKey; className?: string; label?: string }) {
  const { name, url } = organizations[org]

  return (
    <a className={className ? `org-link ${className}` : 'org-link'} href={url} target="_blank" rel="noopener noreferrer">
      {label ?? (org === 'hand' ? (
        <>
          منظمة{' '}
          <bdi lang="en" dir="ltr">
            HAND
          </bdi>
        </>
      ) : (
        name
      ))}
      <svg className="org-link__icon" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
        <path d="M6 3H3v10h10v-3M9 3h4v4M13 3 7.5 8.5" />
      </svg>
      <span className="visually-hidden"> (يفتح في نافذة جديدة)</span>
    </a>
  )
}

/** Prose with organisation links, from structured content (never injected HTML). */
export function RichText({ value }: { value: RichTextValue }) {
  return (
    <>
      {value.map((part, index) =>
        typeof part === 'string' ? <Fragment key={index}>{part}</Fragment> : <OrgLink key={index} org={part.org} label={part.label} />,
      )}
    </>
  )
}
