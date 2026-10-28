import type { ApplicationStatus } from '../adminApi'
import { STATUS_LABELS } from '../adminApi'

/** A readable workflow status; the label carries the meaning, the colour only supports it. */
export function StatusBadge({ status }: { status: ApplicationStatus }) {
  return (
    <span className="admin-status" data-status={status}>
      {STATUS_LABELS[status] ?? status}
    </span>
  )
}
