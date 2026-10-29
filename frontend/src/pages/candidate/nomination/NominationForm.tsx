import { useEffect, useRef, useState } from 'react'
import type { FormEvent, ReactNode } from 'react'
import { FormAlert, StatusIcon, TextField } from '../../../components/forms/FormControls'
import { ApiError, api, errorMessage, NetworkError } from '../../../lib/api'
import { useLeaveWarning, useRouter } from '../../../lib/useRouter'
import { ErrorSummary, TextAreaField } from '../basic/fields'
import type { Errors } from '../basic/formModel'
import { ProfileSummary } from '../basic/ProfileSummary'
import { useAccountFailure } from '../useCandidateRequests'
import type { DomainOption, JourneyOutcome, NominationDetails } from './nominationApi'
import { DiscardWarning, DomainChooser } from './nominationFields'
import {
  buildNominationPayload,
  initialNomination,
  LIMITS,
  NOMINATION_STEPS,
  nominationFieldId,
  nominationHasInput,
  stepOfNominationField,
  validateNominationStep,
} from './nominationModel'
import type { NominationForm as FormState, NominationStepId } from './nominationModel'
import { nominationSummaryFromForm } from './nominationSummaries'

const LEAVE_WARNING = 'لم تُحفظ بيانات هذه المرحلة بعد. إذا غادرت هذه الصفحة فسيُحذف ما أدخلته. هل تريد المغادرة؟'

const LEAVE_WARNING_UNCONFIRMED =
  'لم تتأكد بعد نتيجة حفظ بيانات هذه المرحلة: قد تكون حُفظت وقد لا تكون. إذا غادرت هذه الصفحة فلن يبقى ما أدخلته فيها، ويمكنك التحقق لاحقاً من لوحة المترشح. هل تريد المغادرة؟'

export const STAGE_2_LOCK_WARNING =
  'راجع البيانات بعناية. بعد «حفظ المرحلة والمتابعة» تصبح بيانات هذه المرحلة متاحة للعرض فقط ولا يمكن تعديلها من حسابك. حفظ هذه المرحلة لا يعني تقديم طلب الترشح النهائي.'

type Alert = { tone: 'error' | 'info'; content: ReactNode }

type Pending = { kind: 'domain'; code: string }

/**
 * Stage 2 as three sections inside one form: the domain and subcategory,
 * the work, and the review. Values live in memory only and
 * survive moving between sections and failed requests. Each section is
 * checked by the server before moving on; «حفظ المرحلة والمتابعة» is the
 * only write. A lost answer to that save is never reported as a failure:
 * the form asks the server what this account has before saying anything.
 */
export function NominationForm({
  accountContext,
  journeyKey,
  domains,
  onConfirmed,
}: {
  accountContext: string
  journeyKey: string
  domains: DomainOption[]
  onConfirmed: (details: NominationDetails, alreadyStored: boolean) => void
}) {
  const { setLeaveWarning } = useRouter()
  const handleAccountFailure = useAccountFailure()
  const [form, setForm] = useState<FormState>(initialNomination)
  const [stepIndex, setStepIndex] = useState(0)
  const [errors, setErrors] = useState<Errors>({})
  const [attempt, setAttempt] = useState(0)
  const [alert, setAlert] = useState<Alert | null>(null)
  const [pending, setPending] = useState<Pending | null>(null)
  const [busy, setBusy] = useState(false)
  const [confirmed, setConfirmed] = useState(false)
  const [unconfirmedSave, setUnconfirmedSave] = useState(false)
  const busyRef = useRef(false)
  const headingRef = useRef<HTMLHeadingElement>(null)
  const firstRender = useRef(true)

  const step = NOMINATION_STEPS[stepIndex]
  const dirty = !confirmed && nominationHasInput(form)
  useLeaveWarning(dirty ? (unconfirmedSave ? LEAVE_WARNING_UNCONFIRMED : LEAVE_WARNING) : null)

  useEffect(() => {
    if (firstRender.current) {
      firstRender.current = false
      return
    }
    headingRef.current?.focus()
  }, [stepIndex])

  const update = <K extends keyof FormState>(key: K, value: FormState[K]) => setForm((current) => ({ ...current, [key]: value }))

  const showErrors = (next: Errors) => {
    setErrors(next)
    setAttempt((count) => count + 1)
  }

  /* ---------- Changes that may discard input ask first ---------- */

  const chooseDomain = (code: string) => {
    if (code === form.domain) return
    if (form.subcategory) {
      setPending({ kind: 'domain', code })
      return
    }
    setForm((current) => ({ ...current, domain: code, subcategory: '' }))
  }

  const confirmPending = () => {
    if (!pending) return
    setForm((current) => ({ ...current, domain: pending.code, subcategory: '' }))
    setPending(null)
  }

  const pendingWarning = (kind: Pending['kind'], text: ReactNode, confirmLabel: string, keepLabel: string) =>
    pending?.kind === kind ? (
      <DiscardWarning confirmLabel={confirmLabel} keepLabel={keepLabel} onConfirm={confirmPending} onKeep={() => setPending(null)}>
        {text}
      </DiscardWarning>
    ) : null

  /* ---------- Failures ---------- */

  const showAccountMismatch = (message: string) =>
    setAlert({
      tone: 'error',
      content: (
        <>
          <p>{message}</p>
          <p>
            لحماية البيانات، لا يُرسَل هذا النموذج باسم حساب آخر. ما أدخلته ما زال في هذه الصفحة. للمتابعة، سجّل الخروج من الحساب الآخر وسجّل الدخول بالحساب
            الذي فُتح به النموذج في نافذة أخرى، ثم أعد المحاولة هنا.
          </p>
          <p>
            <button type="button" className="button button--secondary" onClick={() => leaveFor('/dashboard')}>
              تجاهل ما أدخلته والانتقال إلى الحساب المسجّل دخوله الآن
            </button>
          </p>
        </>
      ),
    })

  const leaveFor = (path: string) => {
    setLeaveWarning(null)
    window.location.assign(path)
  }

  const reportFailure = (error: unknown, context: 'check' | 'save') => {
    if (error instanceof ApiError && error.status === 401) {
      setAlert({
        tone: 'error',
        content: (
          <>
            <p>انتهت جلستك، ولم يُحفظ شيء. ما أدخلته ما زال في هذه الصفحة.</p>
            <p>
              <a href="/login" target="_blank" rel="noopener">
                سجّل الدخول بالحساب نفسه في نافذة جديدة
              </a>{' '}
              ثم ارجع إلى هنا وأعد المحاولة.
            </p>
          </>
        ),
      })
      return
    }
    if (error instanceof ApiError && error.code === 'account_mismatch') {
      showAccountMismatch(error.message)
      return
    }
    if (error instanceof ApiError && (error.code === 'account_disabled' || error.code === 'not_candidate')) {
      handleAccountFailure(error)
      return
    }
    if (error instanceof ApiError && (error.code === 'journey_changed' || error.code === 'nomination_form_outdated')) {
      setAlert({
        tone: 'error',
        content: (
          <>
            <p>{error.message}</p>
            <p>
              <button type="button" className="button button--secondary" onClick={() => leaveFor('/dashboard/nomination-details')}>
                إعادة تحميل الصفحة (يُحذف ما أدخلته)
              </button>
            </p>
          </>
        ),
      })
      return
    }
    const text =
      error instanceof NetworkError
        ? context === 'check'
          ? `${error.message} التحقق من القسم لا يحفظ شيئاً، وما أدخلته ما زال هنا.`
          : `${error.message} ما أدخلته ما زال هنا.`
        : errorMessage(error)
    setAlert({ tone: 'error', content: <p>{text}</p> })
  }

  /**
   * A save whose answer never arrived may still have been stored, or may
   * still be running on the server. Ask the server about this form's own
   * journey only (its journey key): its stored stage is shown read-only;
   * anything else, including another cycle's saved stage, stays unconfirmed.
   */
  const reconcile = async () => {
    try {
      const state = await api.get<JourneyOutcome>('/api/candidate/nomination-details/journey', {
        'X-Candidate-Context': accountContext,
        'X-Nomination-Journey': journeyKey,
      })
      if (state.journey.status === 'confirmed' && state.data) {
        finish(state.data, true)
        return
      }
      setUnconfirmedSave(true)
      setAlert({
        tone: 'error',
        content: (
          <>
            <p>لم تصل نتيجة الحفظ: انقطع الاتصال أو تأخّر ردّ الخادم. لا تظهر بيانات محفوظة لهذا النموذج حتى الآن، لكن نتيجة الحفظ لم تتأكد بعد: قد يكون الطلب ما زال قيد المعالجة.</p>
            {state.journey.status !== 'open' && (
              <p>
                {state.journey.status === 'unavailable'
                  ? 'وهذه المرحلة غير متاحة حالياً، فقد تُرفض إعادة المحاولة.'
                  : 'وقد تغيّرت إعدادات هذه المرحلة منذ فتحت النموذج، فقد تُرفض إعادة المحاولة.'}
              </p>
            )}
            <p>ما أدخلته ما زال هنا. أعد المحاولة بعد قليل: إن كان الطلب الأول قد نجح فستظهر بياناتك المحفوظة كما هي، ولن تُحفظ مرة ثانية.</p>
          </>
        ),
      })
    } catch (error) {
      setUnconfirmedSave(true)
      if (error instanceof ApiError && error.code === 'account_mismatch') {
        showAccountMismatch('تعذّر تأكيد نتيجة الحفظ، والحساب المسجّل دخوله الآن في هذا المتصفح غير الحساب الذي فُتح به النموذج.')
        return
      }
      const sessionEnded = error instanceof ApiError && error.status === 401
      setAlert({
        tone: 'error',
        content: (
          <>
            <p>
              تعذّر تأكيد نتيجة الحفظ: ربما حُفظت البيانات وربما لم تُحفظ.{sessionEnded ? ' انتهت جلستك أيضاً.' : ''} ما أدخلته ما زال هنا.
            </p>
            <p>
              {sessionEnded ? (
                <>
                  <a href="/login" target="_blank" rel="noopener">
                    سجّل الدخول بالحساب نفسه في نافذة جديدة
                  </a>{' '}
                  ثم أعد المحاولة هنا.
                </>
              ) : (
                'أعد المحاولة عند عودة الاتصال.'
              )}{' '}
              إن كانت قد حُفظت فسنعرضها لك كما هي، ولن تُحفظ مرة ثانية.
            </p>
          </>
        ),
      })
    }
  }

  /* ---------- Navigation and saving ---------- */

  const guard = async (action: () => Promise<void>) => {
    if (busyRef.current) return
    busyRef.current = true
    setBusy(true)
    setAlert(null)
    try {
      await action()
    } finally {
      busyRef.current = false
      setBusy(false)
    }
  }

  const goNext = () =>
    guard(async () => {
      if (pending) return
      const clientErrors = validateNominationStep(step.id, form, domains)
      if (Object.keys(clientErrors).length > 0) {
        showErrors(clientErrors)
        return
      }
      try {
        await api.post('/api/candidate/nomination-details/check', { section: step.id, ...buildNominationPayload(form, journeyKey, accountContext) })
      } catch (error) {
        if (error instanceof ApiError && error.status === 422) {
          showErrors(firstMessages(error.errors))
          return
        }
        if (error instanceof ApiError && error.code === 'nomination_details_already_confirmed' && error.payload.data) {
          finish(error.payload.data as NominationDetails, true)
          return
        }
        reportFailure(error, 'check')
        return
      }
      setErrors({})
      setStepIndex((index) => index + 1)
    })

  const goTo = (id: NominationStepId) => {
    setErrors({})
    setAlert(null)
    setPending(null)
    setStepIndex(Math.max(0, NOMINATION_STEPS.findIndex((candidate) => candidate.id === id)))
  }

  const submit = () =>
    guard(async () => {
      try {
        const response = await api.post<{ data: NominationDetails }>('/api/candidate/nomination-details', buildNominationPayload(form, journeyKey, accountContext))
        finish(response.data, false)
      } catch (error) {
        await handleSubmitError(error)
      }
    })

  /** Saved: nothing is left to lose, so leaving no longer asks. */
  const finish = (details: NominationDetails, alreadyStored: boolean) => {
    setConfirmed(true)
    setLeaveWarning(null)
    onConfirmed(details, alreadyStored)
  }

  const handleSubmitError = async (error: unknown) => {
    // No answer, or a server error that may have come after the commit.
    if (error instanceof NetworkError || (error instanceof ApiError && error.status >= 500)) {
      await reconcile()
      return
    }
    if (error instanceof ApiError && error.status === 422) {
      const fieldErrors = firstMessages(error.errors)
      goTo(stepOfNominationField(Object.keys(fieldErrors)[0] ?? 'review'))
      showErrors(fieldErrors)
      return
    }
    if (error instanceof ApiError && error.code === 'nomination_details_already_confirmed' && error.payload.data) {
      // This same journey was saved already (the server checked the account
      // and the journey first), for example when its answer was lost.
      finish(error.payload.data as NominationDetails, true)
      return
    }
    reportFailure(error, 'save')
  }

  const onSubmit = (event: FormEvent) => {
    event.preventDefault()
    void (step.id === 'review' ? submit() : goNext())
  }

  return (
    <form className="wizard" onSubmit={onSubmit} noValidate>
      <ol className="stepper" aria-label="أقسام فئة الترشح وبيانات العمل">
        {NOMINATION_STEPS.map((item, index) => (
          <li key={item.id} className="stepper__item" data-state={index < stepIndex ? 'done' : index === stepIndex ? 'current' : 'next'} aria-current={index === stepIndex ? 'step' : undefined}>
            <span className="stepper__number" aria-hidden="true">
              {index < stepIndex ? <StatusIcon tone="success" /> : index + 1}
            </span>
            <span className="stepper__title">
              {item.title}
              {index < stepIndex && <span className="visually-hidden"> (مكتمل)</span>}
            </span>
          </li>
        ))}
      </ol>

      <div className="wizard__panel">
        <p className="wizard__progress">{`القسم ${stepIndex + 1} من ${NOMINATION_STEPS.length}`}</p>
        <h2 ref={headingRef} tabIndex={-1} className="wizard__title">
          {step.title}
        </h2>

        {alert && (
          <FormAlert tone={alert.tone} id="wizard-alert">
            {alert.content}
          </FormAlert>
        )}
        <ErrorSummary errors={errors} attempt={attempt} idFor={nominationFieldId} />

        {step.id === 'domain' && (
          <>
            <p className="wizard__intro">اختر محوراً واحداً، ثم فئة واحدة من فئاته.</p>
            <DomainChooser
              domains={domains}
              domain={form.domain}
              subcategory={form.subcategory}
              onDomain={chooseDomain}
              onSubcategory={(code) => update('subcategory', code)}
              errors={errors}
              warning={pendingWarning(
                'domain',
                <>
                  تغيير المحور إلى «{domains.find((option) => option.code === (pending?.kind === 'domain' ? pending.code : ''))?.name}» يلغي اختيار الفئة الحالية،
                  لأن فئات كل محور خاصة به. بقية بيانات العمل تبقى كما هي.
                </>,
                'تغيير المحور',
                'الإبقاء على المحور الحالي',
              )}
            />
          </>
        )}

        {step.id === 'work' && (
          <div className="form-grid">
            <div className="form-grid__full">
              <TextField id="title" label="عنوان العمل" value={form.title} onChange={(value) => update('title', value)} error={errors.title} autoComplete="off" dir="auto" required />
            </div>
            <div className="form-grid__full">
              <TextAreaField
                id="purpose_and_mission"
                label="الغاية والرسالة"
                value={form.purpose_and_mission}
                onChange={(value) => update('purpose_and_mission', value)}
                error={errors.purpose_and_mission}
                hint="لماذا وُجد هذا العمل، وما الرسالة التي يحملها."
                autoComplete="off"
                rows={4}
                limit={LIMITS.purpose}
              />
            </div>
            <div className="form-grid__full">
              <TextAreaField
                id="objectives"
                label="الأهداف"
                value={form.objectives}
                onChange={(value) => update('objectives', value)}
                error={errors.objectives}
                hint="اكتب كل هدف في سطر مستقل."
                autoComplete="off"
                rows={5}
                limit={LIMITS.objectives}
              />
            </div>
            <div className="form-grid__full">
              <TextAreaField
                id="impact"
                label="الأثر"
                value={form.impact}
                onChange={(value) => update('impact', value)}
                error={errors.impact}
                hint="ما الذي تغيّر فعلاً بفضل العمل، ومن استفاد منه، وما الذي يدل على ذلك. اكتب ما تعرفه، دون أرقام تقديرية."
                autoComplete="off"
                rows={6}
                limit={LIMITS.impact}
              />
            </div>
          </div>
        )}

        {step.id === 'review' && (
          <>
            <div className="lock-warning" role="note">
              <svg className="lock-warning__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <rect x="5" y="10.5" width="14" height="10" rx="1.5" />
                <path d="M8 10.5V7.5a4 4 0 0 1 8 0v3" />
              </svg>
              <p>{STAGE_2_LOCK_WARNING}</p>
            </div>
            <ProfileSummary groups={nominationSummaryFromForm(form, domains)} onEdit={goTo} headingLevel={3} />
          </>
        )}

        <div className="wizard__actions">
          <button type="submit" className="button button--primary" aria-busy={busy || undefined} aria-disabled={busy || pending !== null || undefined}>
            <span className="button__label" data-hidden={busy}>
              {step.id === 'review' ? 'حفظ المرحلة والمتابعة' : 'متابعة'}
            </span>
            {busy && <span className="button__busy">{step.id === 'review' ? 'جارٍ الحفظ…' : 'جارٍ التحقق…'}</span>}
          </button>
          {stepIndex > 0 && (
            <button type="button" className="button button--secondary" onClick={() => goTo(NOMINATION_STEPS[stepIndex - 1].id)} disabled={busy}>
              رجوع
            </button>
          )}
        </div>
      </div>
    </form>
  )
}

/** Laravel returns a list of messages per field; the first is shown. */
function firstMessages(fieldErrors: Record<string, string[]>): Errors {
  return Object.fromEntries(Object.entries(fieldErrors).map(([key, list]) => [key, list[0]]))
}
