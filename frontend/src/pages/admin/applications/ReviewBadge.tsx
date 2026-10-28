import type { ReviewStatus } from '../adminApi'
import { REVIEW_LABELS } from '../adminApi'

/** The administrative classification as text; the colour only supports it. */
export function ReviewBadge({ status }: { status: ReviewStatus }) {
  return (
    <span className="admin-review" data-review={status}>
      {REVIEW_LABELS[status] ?? status}
    </span>
  )
}
