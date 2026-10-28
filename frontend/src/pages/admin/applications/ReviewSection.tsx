import { useId, useState } from 'react'
import type { User } from '../../../auth/useAuth'
import { FormAlert } from '../../../components/forms/FormControls'
import { ApiError, api, errorMessage } from '../../../lib/api'
import { formatDamascusDateTime } from '../../candidate/candidateApi'
import type { ApplicationReview } from '../adminApi'
import { REVIEW_REFUSALS } from '../adminApi'
import { AdminDialog, ConfirmDialog } from '../committees/AdminDialog'
import { ReviewBadge } from './ReviewBadge'

const REASON_MAX = 500

type Notice = { tone: 'success' | 'error' | 'info'; text: string }

/**
 * «التصنيف الإداري» of one submitted application. A pending application can
 * be referred to its main domain's approved judging committee, or excluded
 * and archived with a reason; both ask for confirmation, and the server
 * decides again. Shown actions need applications.review.
 */
export function ReviewSection({
  user,
  applicationId,
  domainName,
  review,
  onChange,
}: {
  user: User
  applicationId: number
  domainName: string
  review: ApplicationReview
  onChange: (review: ApplicationReview) => void
}) {
  const [dialog, setDialog] = useState<'refer' | 'exclude' | null>(null)
  const [reason, setReason] = useState('')
  const [reasonError, setReasonError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [notice, setNotice] = useState<Notice | null>(null)
  const reasonId = useId()
  const canDecide = user.admin_permissions.includes('applications.review')
  const target = review.target
  const committee = target?.committee ?? null

  const decide = async (decision: 'refer' | 'exclude', body: Record<string, unknown>) => {
    setBusy(true)
    setNotice(null)
    try {
      const response = await api.post<{ message: string; data: ApplicationReview }>(`/api/admin/applications/${applicationId}/review/${decision}`, {
        ...body,
        account_context: user.account_context,
      })
      onChange(response.data)
      setNotice({ tone: 'success', text: response.message })
      setDialog(null)
    } catch (error) {
      if (error instanceof ApiError && error.status === 422 && decision === 'exclude') {
        setReasonError(error.errors.reason?.[0] ?? errorMessage(error))
        return
      }
      if (error instanceof ApiError && error.payload.data) onChange(error.payload.data as ApplicationReview)
      setNotice({ tone: 'error', text: errorMessage(error) })
      setDialog(null)
    } finally {
      setBusy(false)
    }
  }

  const exclude = () => {
    const text = reason.trim().replace(/\s+/g, ' ')
    if (!text) {
      setReasonError('اكتب سبب الاستبعاد باختصار.')
      return
    }
    if (Array.from(text).length > REASON_MAX) {
      setReasonError(`سبب الاستبعاد حتى ${REASON_MAX} حرف.`)
      return
    }
    setReasonError(null)
    void decide('exclude', { reason: text })
  }

  return (
    <>
      {notice && (
        <FormAlert tone={notice.tone}>
          <p>{notice.text}</p>
        </FormAlert>
      )}
      <dl className="admin-facts">
        <div>
          <dt>التصنيف</dt>
          <dd>
            <ReviewBadge status={review.status} />
          </dd>
        </div>
        {review.committee && (
          <div>
            <dt>لجنة التحكيم</dt>
            <dd>
              <bdi>{review.committee.name}</bdi>
            </dd>
          </div>
        )}
        {review.status !== 'pending' && (
          <div>
            <dt>القرار</dt>
            <dd>
              {review.legacy
                ? 'إحالة سابقة من سجل التحكيم (قبل المراجعة الأولية؛ لا يُسجَّل لها مدير).'
                : `${review.decided_by ?? '—'}${review.decided_at ? ` · ${formatDamascusDateTime(review.decided_at)} بتوقيت دمشق` : ''}`}
            </dd>
          </div>
        )}
        {review.status === 'excluded' && review.reason && (
          <div>
            <dt>سبب الاستبعاد (للإدارة فقط)</dt>
            <dd>
              <bdi>{review.reason}</bdi>
            </dd>
          </div>
        )}
      </dl>

      {review.status === 'pending' && (
        <>
          <p className="admin-muted">
            لم يُرسل هذا الملف إلى التحكيم بعد. لا يراه الحكام قبل الإرسال إلى اللجنة. يبقى الاستبعاد أرشفةً: يبقى الملف وملفاته متاحين للإدارة ولصاحبه.
          </p>
          {target?.refusal && <p className="admin-requirement">{REVIEW_REFUSALS[target.refusal]}</p>}
          {canDecide && (
            <div className="admin-review-actions">
              <button
                type="button"
                className="button button--primary"
                onClick={() => setDialog('refer')}
                disabled={busy || target?.refusal != null || !committee}
              >
                إرسال إلى اللجنة
              </button>
              <button
                type="button"
                className="button button--secondary"
                onClick={() => {
                  setReason('')
                  setReasonError(null)
                  setDialog('exclude')
                }}
                disabled={busy}
              >
                استبعاد وأرشفة
              </button>
            </div>
          )}
        </>
      )}

      <ConfirmDialog
        open={dialog === 'refer'}
        title="إرسال إلى اللجنة"
        text={
          <p>
            يُرسل ملف الترشح إلى لجنة التحكيم «<bdi>{committee?.name}</bdi>» للمجال الرئيسي «{domainName}»، ويصبح متاحاً لحكامها الحاليين. لا يُلغى هذا الإرسال من هذه
            الصفحة.
          </p>
        }
        confirmLabel="تأكيد الإرسال إلى اللجنة"
        busy={busy}
        onConfirm={() => committee && void decide('refer', { committee_id: committee.id })}
        onClose={() => setDialog(null)}
      />

      <AdminDialog open={dialog === 'exclude'} title="استبعاد وأرشفة" titleId="admin-exclude-title" onClose={() => setDialog(null)}>
        <form
          className="admin-dialog__body admin-form"
          onSubmit={(event) => {
            event.preventDefault()
            exclude()
          }}
          noValidate
        >
          <p>لا يُرسل الملف إلى التحكيم، ويُحفظ في الأرشيف مع سببه. يبقى الملف وملفاته متاحين للإدارة ولصاحبه، ولا يُعرض السبب على المترشح.</p>
          <div className="field">
            <label className="field__label" htmlFor={reasonId}>
              سبب الاستبعاد<span className="field__required"> (مطلوب)</span>
            </label>
            <textarea
              id={reasonId}
              className="field__control"
              rows={3}
              maxLength={REASON_MAX}
              value={reason}
              onChange={(event) => setReason(event.target.value)}
              aria-invalid={reasonError ? true : undefined}
              aria-describedby={reasonError ? `${reasonId}-error` : undefined}
            />
            {reasonError && (
              <p id={`${reasonId}-error`} className="field__error">
                خطأ: {reasonError}
              </p>
            )}
          </div>
          <div className="admin-dialog__actions">
            <button type="button" className="button button--secondary" onClick={() => setDialog(null)}>
              تراجع
            </button>
            <button type="submit" className="button button--primary" disabled={busy} aria-busy={busy || undefined}>
              {busy ? 'جارٍ التنفيذ…' : 'تأكيد الاستبعاد والأرشفة'}
            </button>
          </div>
        </form>
      </AdminDialog>
    </>
  )
}
