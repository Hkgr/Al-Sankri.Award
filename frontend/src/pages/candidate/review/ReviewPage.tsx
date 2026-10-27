import { useCallback, useEffect, useRef, useState } from 'react'
import type { ReactNode } from 'react'
import type { User } from '../../../auth/useAuth'
import { FormAlert, StatusIcon } from '../../../components/forms/FormControls'
import { ApiError, api, errorMessage, NetworkError } from '../../../lib/api'
import { Link } from '../../../lib/router'
import { usePageTitle } from '../../auth/usePageTitle'
import { ProfileSummary } from '../basic/ProfileSummary'
import { summaryFromProfile } from '../basic/summaries'
import type { BasicProfile } from '../candidateApi'
import { contextHeader, formatDamascusDateTime } from '../candidateApi'
import { AttachmentList, LinkList } from '../documentation/AttachmentList'
import type { AttachmentKind, Documentation, Receipt } from '../documentation/documentationApi'
import { SECTION_TITLES, SUBMIT_CONFIRMATION } from '../documentation/documentationApi'
import type { NominationDetails } from '../nomination/nominationApi'
import { DomainArtwork } from '../nomination/nominationFields'
import { nominationSummaryFromDetails } from '../nomination/nominationSummaries'
import { useAccountFailure } from '../useCandidateRequests'

type Review = {
  basic_information: BasicProfile
  nomination_details: NominationDetails
  documentation: Documentation
  submission: Receipt | null
}

type ReviewState = { data: Review | null; journey_key?: string; availability?: { status: string } }

/**
 * The whole participation, read-only, before and after the final
 * submission. It has no editing controls: before submission it links back
 * to Stage 3 only. Submitting sends the documentation revision shown here;
 * if it changed meanwhile (another tab), the server asks for a fresh review.
 */
export function ReviewPage({ user }: { user: User }) {
  usePageTitle('مراجعة المشاركة وإرسالها')
  const handleAccountFailure = useAccountFailure()
  const [state, setState] = useState<ReviewState | null>(null)
  const [stage1Required, setStage1Required] = useState(false)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [alert, setAlert] = useState<{ tone: 'error' | 'info' | 'success'; content: ReactNode } | null>(null)
  const [sending, setSending] = useState(false)
  const dialogRef = useRef<HTMLDialogElement>(null)

  const load = useCallback(async () => {
    setLoadError(null)
    try {
      setState(await api.get<ReviewState>('/api/candidate/review', contextHeader(user)))
    } catch (error) {
      if (error instanceof ApiError && error.code === 'basic_information_required') {
        setStage1Required(true)
        return
      }
      if (!handleAccountFailure(error)) setLoadError(errorMessage(error))
    }
  }, [handleAccountFailure, user])

  useEffect(() => {
    // Fetching on mount: the state is set once the request settles.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load()
  }, [load])

  const review = state?.data ?? null

  const submit = async () => {
    dialogRef.current?.close()
    if (!review || !state?.journey_key) return
    setSending(true)
    setAlert(null)
    try {
      const response = await api.post<{ data: Receipt }>('/api/candidate/submission', {
        reviewed_revision: review.documentation.revision,
        account_context: user.account_context,
        journey_key: state.journey_key,
      })
      setState({ ...state, data: { ...review, submission: response.data } })
    } catch (error) {
      if (error instanceof ApiError && error.code === 'review_outdated') {
        setAlert({ tone: 'error', content: <p>{error.message}</p> })
        await load()
      } else if (error instanceof NetworkError || (error instanceof ApiError && error.status >= 500)) {
        await reconcile()
      } else if (!handleAccountFailure(error)) {
        setAlert({ tone: 'error', content: <p>{errorMessage(error)}</p> })
      }
    } finally {
      setSending(false)
    }
  }

  /** The answer was lost: the submission may have been recorded anyway. */
  const reconcile = async () => {
    try {
      const fresh = await api.get<ReviewState>('/api/candidate/review', contextHeader(user))
      setState(fresh)
      if (fresh.data?.submission) return
    } catch {
      // Still unknown: say so below.
    }
    setAlert({
      tone: 'error',
      content: (
        <p>
          لم تصل نتيجة الإرسال، ولا يظهر أن المشاركة أُرسلت حتى الآن، لكن ذلك لم يتأكد بعد. أعد المحاولة بعد قليل: إن كان الإرسال الأول قد نجح فستظهر المشاركة المرسلة
          نفسها، ولن تُرسل مرة ثانية.
        </p>
      ),
    })
  }

  return (
    <div className="candidate-page">
      <header className="candidate-page__header">
        <p className="eyebrow">المراجعة والإرسال</p>
        <h1 className="candidate-page__title">مراجعة المشاركة</h1>
        <p className="candidate-page__intro">
          {review?.submission ? 'هذه المشاركة كما أُرسلت. هي متاحة للعرض فقط.' : 'راجع بيانات المشاركة كلها كما حُفظت، ثم أرسلها للجنة الحكام.'}
        </p>
      </header>

      {loadError ? (
        <FormAlert tone="error">
          <p>{loadError}</p>
          <p>
            <button type="button" className="button button--secondary" onClick={() => void load()}>
              إعادة المحاولة
            </button>
          </p>
        </FormAlert>
      ) : stage1Required || (state && !review) ? (
        <FormAlert tone="info">
          <p>تُتاح المراجعة بعد استكمال المراحل الثلاث: البيانات الأساسية، وفئة الترشح وبيانات العمل، وملفات العمل وتوثيقه.</p>
          <p>
            <Link href="/dashboard">العودة إلى لوحة المترشح</Link>
          </p>
        </FormAlert>
      ) : !review || !state?.journey_key ? (
        <p className="candidate-loading" role="status">
          جارٍ تحميل المشاركة…
        </p>
      ) : (
        <>
          {review.submission ? (
            <div className="locked-banner submitted-banner" role="status">
              <StatusIcon tone="success" />
              <div>
                <p className="locked-banner__title">تم إرسال المشاركة</p>
                <p>
                  الرقم المرجعي: <bdi dir="ltr">{review.submission.reference_number}</bdi>
                </p>
                <p>وقت الإرسال: {formatDamascusDateTime(review.submission.submitted_at)} بتوقيت دمشق.</p>
                <p>سُجّلت المشاركة لتنتقل إلى لجنة الحكام وفق مراحل الجائزة. لا يمكن تعديلها أو إضافة ملفات إليها بعد الإرسال.</p>
              </div>
            </div>
          ) : !review.documentation.confirmed ? (
            <FormAlert tone="info">
              <p>لم يُؤكَّد حفظ ملفات العمل وتوثيقه (المرحلة 3) بصورتها الحالية. احفظ المرحلة ثم عد إلى هنا للإرسال.</p>
              <p>
                <Link href="/dashboard/documentation">الانتقال إلى ملفات العمل وتوثيقه</Link>
              </p>
            </FormAlert>
          ) : null}
          {alert && <FormAlert tone={alert.tone}>{alert.content}</FormAlert>}

          <section className="review-part" aria-labelledby="review-stage-1">
            <h2 id="review-stage-1" className="review-part__title">
              المرحلة 1: البيانات الأساسية
            </h2>
            <ProfileSummary groups={summaryFromProfile(review.basic_information)} headingLevel={3} />
          </section>

          <section className="review-part" aria-labelledby="review-stage-2">
            <h2 id="review-stage-2" className="review-part__title">
              المرحلة 2: فئة الترشح وبيانات العمل
            </h2>
            <div className="domain-preview domain-preview--summary">
              <DomainArtwork artwork={review.nomination_details.domain.artwork} domainName={review.nomination_details.domain.name} size="preview" />
              <div className="domain-preview__text">
                <p className="domain-preview__name">{review.nomination_details.domain.name}</p>
                <p className="domain-preview__sub">{review.nomination_details.subcategory.name}</p>
              </div>
            </div>
            <ProfileSummary groups={nominationSummaryFromDetails(review.nomination_details)} headingLevel={3} />
          </section>

          <section className="review-part" aria-labelledby="review-stage-3">
            <h2 id="review-stage-3" className="review-part__title">
              المرحلة 3: ملفات العمل وتوثيقه
            </h2>
            {(['explanatory', 'supporting', 'video'] as AttachmentKind[]).map((kind) => (
              <div key={kind} className="summary-group">
                <h3 className="summary-group__title">{SECTION_TITLES[kind]}</h3>
                <AttachmentList
                  attachments={review.documentation.attachments.filter((item) => item.kind === kind)}
                  user={user}
                  journeyKey={state.journey_key!}
                  emptyText="لم يُضف شيء."
                />
              </div>
            ))}
            <div className="summary-group">
              <h3 className="summary-group__title">روابط توثيق إضافية</h3>
              <LinkList links={review.documentation.links} emptyText="لم تُضف روابط." />
            </div>
          </section>

          {!review.submission && (
            <div className="review-actions">
              <Link className="button button--secondary" href="/dashboard/documentation">
                العودة إلى ملفات العمل
              </Link>
              {review.documentation.confirmed && (
                <button type="button" className="button button--primary" onClick={() => dialogRef.current?.showModal()} disabled={sending}>
                  {sending ? 'جارٍ الإرسال…' : 'إرسال للجنة الحكام'}
                </button>
              )}
            </div>
          )}
          {review.submission && (
            <p className="candidate-page__back">
              <Link href="/dashboard">العودة إلى لوحة المترشح</Link>
            </p>
          )}

          <dialog ref={dialogRef} className="confirm-dialog" aria-labelledby="confirm-dialog-title" aria-describedby="confirm-dialog-text">
            <h2 id="confirm-dialog-title" className="confirm-dialog__title">
              تأكيد الإرسال
            </h2>
            <p id="confirm-dialog-text">{SUBMIT_CONFIRMATION}</p>
            <div className="confirm-dialog__actions">
              <button type="button" className="button button--secondary" onClick={() => dialogRef.current?.close()} autoFocus>
                العودة للمراجعة
              </button>
              <button type="button" className="button button--primary" onClick={() => void submit()}>
                تأكيد الإرسال
              </button>
            </div>
          </dialog>
        </>
      )}
    </div>
  )
}
