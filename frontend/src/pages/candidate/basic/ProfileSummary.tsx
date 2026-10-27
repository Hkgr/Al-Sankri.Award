import type { ReactNode } from 'react'
import type { StepId } from './formModel'

type Row = { label: string; value: ReactNode | null; ltr?: boolean }

/** Built by summaryFromForm / summaryFromProfile (summaries.tsx). */
export type SummaryGroup<S extends string = StepId> = { title: string; step?: S; rows: Row[] }

/** Shown for an optional field left empty. */
const NOT_ADDED = 'لم يُضف'

function Ltr({ children }: { children: ReactNode }) {
  return <bdi dir="ltr">{children}</bdi>
}

/** Social links: the platform name, then the address read left to right. */
export function SummaryLinks({ links }: { links: { platform: string; url: string }[] }) {
  return (
    <ul className="summary-links">
      {links.map((link, index) => (
        <li key={`${index}-${link.url}`}>
          <bdi>{link.platform}</bdi>: <Ltr>{link.url}</Ltr>
        </li>
      ))}
    </ul>
  )
}

/**
 * Groups of label/value rows. In the review step each group has an «تعديل»
 * button leading back to its section; the read-only view has none.
 */
export function ProfileSummary<S extends string = StepId>({ groups, onEdit, headingLevel = 2 }: { groups: SummaryGroup<S>[]; onEdit?: (step: S) => void; headingLevel?: 2 | 3 }) {
  const Heading = headingLevel === 2 ? 'h2' : 'h3'

  return (
    <div className="summary">
      {groups.map((group) => (
        <section key={group.title} className="summary-group" aria-label={group.title}>
          <div className="summary-group__header">
            <Heading className="summary-group__title">{group.title}</Heading>
            {onEdit && group.step && (
              <button type="button" className="button button--secondary summary-group__edit" onClick={() => onEdit(group.step!)}>
                تعديل<span className="visually-hidden"> {group.title}</span>
              </button>
            )}
          </div>
          <dl className="summary-list">
            {group.rows.map((row) => (
              <div key={row.label} className="summary-list__row">
                <dt>{row.label}</dt>
                <dd>
                  {row.value === null || row.value === '' ? (
                    <span className="summary-list__empty">{NOT_ADDED}</span>
                  ) : row.ltr ? (
                    <Ltr>{row.value}</Ltr>
                  ) : (
                    // Text is isolated; structured values (links) isolate their own parts.
                    typeof row.value === 'string' ? <bdi>{row.value}</bdi> : row.value
                  )}
                </dd>
              </div>
            ))}
          </dl>
        </section>
      ))}
    </div>
  )
}
