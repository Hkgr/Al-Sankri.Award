import { useEffect, useRef, useState } from 'react'
import type { FormEvent, ReactNode } from 'react'
import { FormAlert, StatusIcon, TextField } from '../../../components/forms/FormControls'
import { ApiError, api, errorMessage, NetworkError } from '../../../lib/api'
import { useLeaveWarning, useRouter } from '../../../lib/useRouter'
import { CheckboxField, ErrorSummary, SelectField, TextAreaField } from '../basic/fields'
import type { Errors } from '../basic/formModel'
import { ProfileSummary } from '../basic/ProfileSummary'
import { useAccountFailure } from '../useCandidateRequests'
import { FUNDING_LABELS } from './nominationApi'
import type { DomainOption, FundingType, Governorate, JourneyOutcome, NominationDetails } from './nominationApi'
import { DatePartsField, DiscardWarning, DomainChooser, LocationRows, PartnerRows } from './nominationFields'
import {
  buildNominationPayload,
  emptyLocation,
  emptyPartner,
  FUNDED_BY_OTHERS,
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

type Pending =
  | { kind: 'domain'; code: string }
  | { kind: 'funding'; value: FundingType | '' }
  | { kind: 'partnerships' }

/**
 * Stage 2 as five sections inside one form. Values live in memory only and
 * survive moving between sections and failed requests. Each section is
 * checked by the server before moving on; «حفظ المرحلة والمتابعة» is the
 * only write. A lost answer to that save is never reported as a failure:
 * the form asks the server what this account has before saying anything.
 */
export function NominationForm({
  accountContext,
  journeyKey,
  domains,
  governorates,
  countryName,
  onConfirmed,
}: {
  accountContext: string
  journeyKey: string
  domains: DomainOption[]
  governorates: Governorate[]
  countryName: string
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

  /** Funding values that a change of declaration would discard. */
  const fundingLoss = (next: FundingType | ''): string[] => {
    const current = form.funding_type
    const lost: string[] = []
    const keepsFunder = next !== '' && FUNDED_BY_OTHERS.includes(next) && current !== '' && FUNDED_BY_OTHERS.includes(current)
    const keepsAmount = keepsFunder || (next === 'self_funded' && current === 'self_funded')
    if (!keepsFunder && form.funder_name.trim()) lost.push('اسم الجهة الممولة')
    if (!keepsAmount && form.funding_amount.trim()) lost.push('المبلغ')
    return lost
  }

  const applyFunding = (next: FundingType | '') =>
    setForm((current) => {
      const keepsFunder = next !== '' && FUNDED_BY_OTHERS.includes(next) && current.funding_type !== '' && FUNDED_BY_OTHERS.includes(current.funding_type)
      const keepsAmount = keepsFunder || (next === 'self_funded' && current.funding_type === 'self_funded')
      return { ...current, funding_type: next, funder_name: keepsFunder ? current.funder_name : '', funding_amount: keepsAmount ? current.funding_amount : '' }
    })

  const chooseFunding = (next: string) => {
    const value = next as FundingType | ''
    if (fundingLoss(value).length > 0) {
      setPending({ kind: 'funding', value })
      return
    }
    applyFunding(value)
  }

  const choosePartnerships = (answer: 'yes' | 'no') => {
    if (answer === 'no' && form.partners.some((row) => row.name.trim() || row.contribution.trim())) {
      setPending({ kind: 'partnerships' })
      return
    }
    setForm((current) => ({
      ...current,
      has_partnerships: answer,
      partners: answer === 'yes' ? (current.partners.length ? current.partners : [emptyPartner()]) : [],
    }))
  }

  const confirmPending = () => {
    if (!pending) return
    if (pending.kind === 'domain') setForm((current) => ({ ...current, domain: pending.code, subcategory: '' }))
    if (pending.kind === 'funding') applyFunding(pending.value)
    if (pending.kind === 'partnerships') setForm((current) => ({ ...current, has_partnerships: 'no', partners: [] }))
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
    if (error instanceof ApiError && error.code === 'journey_changed') {
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

  const fundingNeedsFunder = form.funding_type !== '' && FUNDED_BY_OTHERS.includes(form.funding_type)
  const fundingNeedsAmount = form.funding_type !== '' && form.funding_type !== 'not_needed'

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

        {step.id === 'dates_locations' && (
          <div className="form-grid">
            <div className="form-grid__full">
              <DatePartsField idBase="started_on" label="تاريخ بدء العمل" hint="بالتقويم الميلادي." value={form.start} onChange={(value) => update('start', value)} error={errors.started_on} />
            </div>
            <div className="form-grid__full">
              <CheckboxField
                id="ongoing"
                checked={form.ongoing}
                onChange={(checked) => setForm((current) => ({ ...current, ongoing: checked, end: checked ? { day: '', month: '', year: '' } : current.end }))}
                error={errors.implementation_state}
              >
                العمل مستمر (لا يوجد تاريخ انتهاء)
              </CheckboxField>
            </div>
            {!form.ongoing && (
              <div className="form-grid__full">
                <DatePartsField idBase="ended_on" label="تاريخ انتهاء العمل" hint="للعمل المنتهي. لا يسبق تاريخ البدء." value={form.end} onChange={(value) => update('end', value)} error={errors.ended_on} />
              </div>
            )}
            <LocationRows rows={form.locations} onChange={(rows) => update('locations', rows)} governorates={governorates} countryName={countryName} errors={errors} newRow={emptyLocation} />
          </div>
        )}

        {step.id === 'funding' && (
          <div className="form-grid">
            <div className="form-grid__full">
              <SelectField
                id="funding_type"
                label="الإقرار المالي"
                value={form.funding_type}
                onChange={chooseFunding}
                options={(Object.keys(FUNDING_LABELS) as FundingType[]).map((value) => ({ value, label: FUNDING_LABELS[value] }))}
                placeholder="اختر الإقرار المالي"
                error={errors.funding_type}
                hint="المبالغ بالدولار الأمريكي فقط. لا تُعرض البيانات المالية علناً."
              />
            </div>
            {pendingWarning(
              'funding',
              <>
                تغيير الإقرار المالي يحذف ما لم يعد ينطبق: {pending?.kind === 'funding' ? fundingLoss(pending.value).join(' و') : ''}.
              </>,
              'تغيير الإقرار وحذف ذلك',
              'الإبقاء على الإقرار الحالي',
            )}
            {fundingNeedsFunder && (
              <TextField id="funder_name" label="اسم الجهة الممولة" value={form.funder_name} onChange={(value) => update('funder_name', value)} error={errors.funder_name} autoComplete="off" dir="auto" required />
            )}
            {fundingNeedsAmount && (
              <TextField
                id="funding_amount"
                label={form.funding_type === 'self_funded' ? 'المبلغ المموَّل ذاتياً بالدولار الأمريكي (USD)' : 'مبلغ التمويل من الجهة الممولة بالدولار الأمريكي (USD)'}
                value={form.funding_amount}
                onChange={(value) => update('funding_amount', value)}
                error={errors.funding_amount}
                hint="بالأرقام، مع نقطة للكسور العشرية عند الحاجة، مثل 1500 أو 1500.50، ودون فواصل للآلاف."
                autoComplete="off"
                dir="ltr"
                inputMode="decimal"
                required
              />
            )}

            <fieldset className="field field-group field-group--full" aria-describedby={errors.has_partnerships ? 'has_partnerships-error' : undefined}>
              <legend className="field__label">
                هل توجد شراكات مرتبطة بالعمل؟<span className="field__required"> (مطلوب)</span>
              </legend>
              <div className="radio-row">
                {(['yes', 'no'] as const).map((answer) => (
                  <label key={answer} className="choice">
                    <input
                      id={`has_partnerships-${answer}`}
                      type="radio"
                      name="has_partnerships"
                      checked={form.has_partnerships === answer}
                      onChange={() => choosePartnerships(answer)}
                      aria-invalid={errors.has_partnerships ? true : undefined}
                    />
                    <span>{answer === 'yes' ? 'نعم' : 'لا'}</span>
                  </label>
                ))}
              </div>
              {errors.has_partnerships && (
                <p id="has_partnerships-error" className="field__error">
                  <StatusIcon tone="error" />
                  <span>خطأ: {errors.has_partnerships}</span>
                </p>
              )}
            </fieldset>
            {pendingWarning('partnerships', 'اختيار «لا» يحذف بيانات الشركاء التي أدخلتها.', 'اختيار «لا» وحذف الشركاء', 'الإبقاء على الشركاء')}
            {form.has_partnerships === 'yes' && <PartnerRows rows={form.partners} onChange={(rows) => update('partners', rows)} errors={errors} newRow={emptyPartner} />}
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
            <ProfileSummary groups={nominationSummaryFromForm(form, domains, governorates)} onEdit={goTo} headingLevel={3} />
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
