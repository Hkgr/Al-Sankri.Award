import { useEffect, useRef, useState } from 'react'
import type { FormEvent, ReactNode } from 'react'
import { useAuth } from '../../../auth/useAuth'
import type { User } from '../../../auth/useAuth'
import { FormAlert, StatusIcon, TextField } from '../../../components/forms/FormControls'
import { ApiError, api, errorMessage, messages, NetworkError } from '../../../lib/api'
import { useLeaveWarning, useRouter } from '../../../lib/useRouter'
import { POLICY_NAMES, POLICY_SLUGS, PROFILE_TYPE_LABELS } from '../candidateApi'
import type { BasicProfile, Country, PolicyCode, ProfileType, Requirements } from '../candidateApi'
import { useAccountFailure } from '../useCandidateRequests'
import { VerifyEmailNotice } from '../VerifyEmailNotice'
import { BirthDateField, CheckboxField, ErrorSummary, PhoneField, RadioGroup, SelectField, SocialLinksField, TextAreaField } from './fields'
import { branchHasInput, buildPayload, countryOptions, emptyIndividual, emptyInstitution, initialState, stepOfField, stepsFor, validateStep } from './formModel'
import type { Errors, FormState, InstitutionForm, IndividualForm, StepId } from './formModel'
import { ProfileSummary } from './ProfileSummary'
import { summaryFromForm } from './summaries'

const LEAVE_WARNING = 'لم تُحفظ بياناتك الأساسية بعد. إذا غادرت هذه الصفحة فسيُحذف ما أدخلته. هل تريد المغادرة؟'

export const LOCK_WARNING = 'يرجى مراجعة بياناتك بعناية. بعد تأكيد الحفظ، ستصبح البيانات الأساسية متاحة للعرض فقط ولن تتمكن من تعديلها من حسابك.'

type Alert = { tone: 'error' | 'info'; content: ReactNode }

/**
 * Stage 1 as a short sequence of sections inside one form: the applicant
 * type, two sections for the chosen type, then review and declarations.
 * Values live in memory only and survive moving between sections and
 * failed requests. Each section is checked by the server before moving on;
 * the final confirmation is the only write.
 */
export function BasicInformationForm({
  user,
  countries,
  requirements,
  onRequirementsChange,
  onConfirmed,
}: {
  user: User
  countries: Country[]
  requirements: Requirements
  onRequirementsChange: (requirements: Requirements) => void
  onConfirmed: (profile: BasicProfile, alreadyStored: boolean) => void
}) {
  const { refreshUser } = useAuth()
  const { setLeaveWarning } = useRouter()
  const handleAccountFailure = useAccountFailure()
  const [form, setForm] = useState<FormState>(() => initialState(user.email))
  const [stepIndex, setStepIndex] = useState(0)
  const [errors, setErrors] = useState<Errors>({})
  const [attempt, setAttempt] = useState(0)
  const [alert, setAlert] = useState<Alert | null>(null)
  const [pendingType, setPendingType] = useState<ProfileType | null>(null)
  const [busy, setBusy] = useState(false)
  const [confirmed, setConfirmed] = useState(false)
  const busyRef = useRef(false)
  const headingRef = useRef<HTMLHeadingElement>(null)
  const firstStepRender = useRef(true)

  const steps = stepsFor(form.profileType)
  const step = steps[Math.min(stepIndex, steps.length - 1)]
  const dirty = !confirmed && (form.profileType !== '' || branchHasInput(form, 'individual', user.email) || branchHasInput(form, 'institution', user.email))
  useLeaveWarning(dirty ? LEAVE_WARNING : null)

  // Each new section starts at its heading, which is announced.
  useEffect(() => {
    if (firstStepRender.current) {
      firstStepRender.current = false
      return
    }
    headingRef.current?.focus()
  }, [stepIndex])

  const showErrors = (next: Errors) => {
    setErrors(next)
    setAttempt((count) => count + 1)
  }

  const updateIndividual = <K extends keyof IndividualForm>(key: K, value: IndividualForm[K]) =>
    setForm((current) => ({ ...current, individual: { ...current.individual, [key]: value } }))

  const updateInstitution = <K extends keyof InstitutionForm>(key: K, value: InstitutionForm[K]) =>
    setForm((current) => ({ ...current, institution: { ...current.institution, [key]: value } }))

  const setDeclaration = (key: keyof FormState['declarations'], value: boolean) =>
    setForm((current) => ({ ...current, declarations: { ...current.declarations, [key]: value } }))

  const chooseType = (type: ProfileType) => {
    const current = form.profileType
    if (current && current !== type && branchHasInput(form, current, user.email)) {
      setPendingType(type)
      return
    }
    setPendingType(null)
    setForm((state) => ({ ...state, profileType: type }))
  }

  const confirmTypeChange = () => {
    if (!pendingType) return
    const discarded = form.profileType
    setForm((state) => ({
      ...state,
      profileType: pendingType,
      individual: discarded === 'individual' ? emptyIndividual(user.email) : state.individual,
      institution: discarded === 'institution' ? emptyInstitution() : state.institution,
      declarations: { ...state.declarations, representation: false },
    }))
    setPendingType(null)
    setErrors({})
  }

  /** Handles a failed request without losing the input. */
  const reportFailure = (error: unknown) => {
    if (error instanceof ApiError && error.status === 401) {
      setAlert({
        tone: 'error',
        content: (
          <>
            <p>انتهت جلستك، ولم يُحفظ شيء. ما أدخلته ما زال في هذه الصفحة.</p>
            <p>
              <a href="/login" target="_blank" rel="noopener">
                سجّل الدخول في نافذة جديدة
              </a>{' '}
              ثم ارجع إلى هنا وأعد المحاولة.
            </p>
          </>
        ),
      })
      return
    }
    if (error instanceof ApiError && (error.code === 'account_disabled' || error.code === 'not_candidate')) {
      handleAccountFailure(error)
      return
    }
    const text = error instanceof NetworkError ? `${messages.network} لم يُحفظ شيء، وما أدخلته ما زال هنا.` : errorMessage(error)
    setAlert({ tone: 'error', content: <p>{text}</p> })
  }

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
      if (pendingType) return
      const clientErrors = validateStep(step.id, form, requirements)
      if (Object.keys(clientErrors).length > 0) {
        showErrors(clientErrors)
        return
      }
      if (step.id !== 'type') {
        try {
          await api.post('/api/candidate/basic-profile/check', { section: step.id, ...buildPayload(form, requirements) })
        } catch (error) {
          if (error instanceof ApiError && error.status === 422) {
            showErrors(firstMessages(error.errors))
            return
          }
          reportFailure(error)
          return
        }
      }
      setErrors({})
      setStepIndex((index) => index + 1)
    })

  const goBack = () => {
    setErrors({})
    setAlert(null)
    setPendingType(null)
    setStepIndex((index) => Math.max(0, index - 1))
  }

  const goTo = (id: StepId) => {
    setErrors({})
    setAlert(null)
    setStepIndex(Math.max(0, steps.findIndex((candidate) => candidate.id === id)))
  }

  const submit = () =>
    guard(async () => {
      const clientErrors = validateStep('review', form, requirements)
      if (Object.keys(clientErrors).length > 0) {
        showErrors(clientErrors)
        return
      }
      try {
        const response = await api.post<{ data: BasicProfile }>('/api/candidate/basic-profile', buildPayload(form, requirements))
        finish(response.data, false)
      } catch (error) {
        handleSubmitError(error)
      }
    })

  /** Saved: nothing is left to lose, so leaving no longer asks. */
  const finish = (profile: BasicProfile, alreadyStored: boolean) => {
    setConfirmed(true)
    setLeaveWarning(null)
    onConfirmed(profile, alreadyStored)
  }

  const handleSubmitError = (error: unknown) => {
    if (!(error instanceof ApiError)) {
      reportFailure(error)
      return
    }
    if (error.status === 422) {
      const fieldErrors = firstMessages(error.errors)
      const firstStep = stepOfField(Object.keys(fieldErrors)[0] ?? 'review')
      goTo(firstStep)
      showErrors(fieldErrors)
      return
    }
    if (error.code === 'profile_already_completed' && error.payload.data) {
      // A previous attempt was saved (for example its answer was lost).
      finish(error.payload.data as BasicProfile, true)
      return
    }
    if (error.code === 'policy_changed' || error.code === 'policies_not_configured') {
      if (error.payload.requirements) onRequirementsChange(error.payload.requirements as Requirements)
      setForm((state) => ({ ...state, declarations: { ...state.declarations, privacy_policy: false, terms_of_use: false } }))
      setAlert({ tone: 'error', content: <p>{error.message}</p> })
      return
    }
    if (error.code === 'email_unverified') {
      void refreshUser().catch(() => undefined)
      setAlert({ tone: 'error', content: <p>{error.message}</p> })
      return
    }
    reportFailure(error)
  }

  const onSubmit = (event: FormEvent) => {
    event.preventDefault()
    void (step.id === 'review' ? submit() : goNext())
  }

  return (
    <form className="wizard" onSubmit={onSubmit} noValidate>
      <ol className="stepper" aria-label="أقسام البيانات الأساسية">
        {steps.map((item, index) => (
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
        <p className="wizard__progress">
          {form.profileType ? `القسم ${stepIndex + 1} من ${steps.length}` : 'القسم الأول'}
        </p>
        <h2 ref={headingRef} tabIndex={-1} className="wizard__title">
          {step.title}
        </h2>

        {alert && (
          <FormAlert tone={alert.tone} id="wizard-alert">
            {alert.content}
          </FormAlert>
        )}
        <ErrorSummary errors={errors} attempt={attempt} />

        {step.id === 'type' && (
          <TypeStep
            value={form.profileType}
            pendingType={pendingType}
            error={errors.profile_type}
            onChoose={chooseType}
            onConfirmChange={confirmTypeChange}
            onCancelChange={() => setPendingType(null)}
            emailVerified={user.email_verified}
          />
        )}
        {step.id === 'individual_identity' && <IndividualIdentity form={form.individual} update={updateIndividual} errors={errors} countries={countries} />}
        {step.id === 'individual_contact' && <IndividualContact form={form.individual} update={updateIndividual} errors={errors} countries={countries} loginEmail={user.email} />}
        {step.id === 'institution_details' && <InstitutionDetails form={form.institution} update={updateInstitution} errors={errors} countries={countries} />}
        {step.id === 'institution_contact' && <InstitutionContact form={form.institution} update={updateInstitution} errors={errors} countries={countries} />}
        {step.id === 'review' && (
          <ReviewStep form={form} countries={countries} requirements={requirements} errors={errors} onEdit={goTo} setDeclaration={setDeclaration} emailVerified={user.email_verified} />
        )}

        <div className="wizard__actions">
          {step.id === 'review' ? (
            <button type="submit" className="button button--primary" aria-busy={busy || undefined} aria-disabled={busy || undefined}>
              <span className="button__label" data-hidden={busy}>
                تأكيد وحفظ البيانات
              </span>
              {busy && <span className="button__busy">جارٍ الحفظ…</span>}
            </button>
          ) : (
            <button type="submit" className="button button--primary" aria-busy={busy || undefined} aria-disabled={busy || pendingType !== null || undefined}>
              <span className="button__label" data-hidden={busy}>
                متابعة
              </span>
              {busy && <span className="button__busy">جارٍ التحقق…</span>}
            </button>
          )}
          {stepIndex > 0 && (
            <button type="button" className="button button--secondary" onClick={goBack} disabled={busy}>
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

/* ---------- Sections ---------- */

function TypeStep({
  value,
  pendingType,
  error,
  onChoose,
  onConfirmChange,
  onCancelChange,
  emailVerified,
}: {
  value: ProfileType | ''
  pendingType: ProfileType | null
  error?: string
  onChoose: (type: ProfileType) => void
  onConfirmChange: () => void
  onCancelChange: () => void
  emailVerified: boolean
}) {
  const options: { value: ProfileType; title: string; text: string }[] = [
    { value: 'individual', title: 'فرد', text: 'شخص يتقدّم باسمه.' },
    { value: 'institution', title: 'مؤسسة', text: 'جهة يتقدّم باسمها صاحب هذا الحساب بصفته ممثلاً لها.' },
  ]

  return (
    <>
      <p className="wizard__intro">ستُطلب بعد ذلك بيانات النوع الذي تختاره فقط. يمكنك تغيير النوع قبل تأكيد الحفظ.</p>
      {!emailVerified && <VerifyEmailNotice context="form" />}
      <fieldset className="choice-cards" aria-describedby={error ? 'profile_type-error' : undefined}>
        <legend className="field__label">
          من يتقدّم؟<span className="field__required"> (مطلوب)</span>
        </legend>
        <div className="choice-cards__grid">
          {options.map((option) => (
            <label key={option.value} className="choice-card" data-checked={value === option.value}>
              <input
                id={`profile_type-${option.value}`}
                type="radio"
                name="profile_type"
                value={option.value}
                checked={value === option.value}
                onChange={() => onChoose(option.value)}
                aria-describedby={`profile_type-${option.value}-text`}
              />
              <span className="choice-card__title">{option.title}</span>
              <span id={`profile_type-${option.value}-text`} className="choice-card__text">
                {option.text}
              </span>
            </label>
          ))}
        </div>
        {error && (
          <p id="profile_type-error" className="field__error">
            <StatusIcon tone="error" />
            <span>خطأ: {error}</span>
          </p>
        )}
      </fieldset>

      {pendingType && value && (
        <div className="form-alert form-alert--error type-change" role="alert">
          <StatusIcon tone="error" />
          <div className="form-alert__body">
            <p>
              تغيير نوع المتقدم إلى «{PROFILE_TYPE_LABELS[pendingType]}» سيحذف كل ما أدخلته من بيانات «{PROFILE_TYPE_LABELS[value]}». هل تريد المتابعة؟
            </p>
            <div className="candidate-actions">
              <button type="button" className="button button--primary" onClick={onConfirmChange}>
                تغيير النوع وحذف البيانات
              </button>
              <button type="button" className="button button--secondary" onClick={onCancelChange}>
                الإبقاء على «{PROFILE_TYPE_LABELS[value]}»
              </button>
            </div>
          </div>
        </div>
      )}
    </>
  )
}

type SectionProps<T> = {
  form: T
  update: <K extends keyof T>(key: K, value: T[K]) => void
  errors: Errors
  countries: Country[]
}

function IndividualIdentity({ form, update, errors, countries }: SectionProps<IndividualForm>) {
  return (
    <div className="form-grid">
      <div className="form-grid__full name-fields">
        <TextField id="individual-first_name" label="الاسم الأول" value={form.first_name} onChange={(value) => update('first_name', value)} error={errors['individual.first_name']} autoComplete="given-name" dir="auto" maxLength={80} required />
        <TextField id="individual-middle_name" label="الاسم الأوسط" value={form.middle_name} onChange={(value) => update('middle_name', value)} error={errors['individual.middle_name']} autoComplete="additional-name" dir="auto" maxLength={80} optional />
        <TextField
          id="individual-last_name"
          label="الاسم الأخير"
          value={form.last_name}
          onChange={(value) => update('last_name', value)}
          error={errors['individual.last_name']}
          hint="اكتب الاسم كما في وثيقتك الرسمية، بالعربية أو بغيرها."
          autoComplete="family-name"
          dir="auto"
          maxLength={80}
          required
        />
      </div>
      <div className="form-grid__full">
        <BirthDateField day={form.birth_day} month={form.birth_month} year={form.birth_year} onChange={(part, value) => update(part, value)} error={errors['individual.birth_date']} />
      </div>
      <SelectField id="individual-nationality" label="الجنسية" value={form.nationality} onChange={(value) => update('nationality', value)} options={countryOptions(countries)} placeholder="اختر الجنسية" error={errors['individual.nationality']} />
      <RadioGroup
        name="individual.gender"
        label="الجنس"
        value={form.gender}
        onChange={(value) => update('gender', value)}
        options={[
          { value: 'male', label: 'ذكر' },
          { value: 'female', label: 'أنثى' },
        ]}
        error={errors['individual.gender']}
      />
      <TextField
        id="individual-national_id_number"
        label="رقم الهوية الشخصية"
        value={form.national_id_number}
        onChange={(value) => update('national_id_number', value)}
        error={errors['individual.national_id_number']}
        hint="كما هو في وثيقتك، مع الأصفار في أوله والحروف والفواصل إن وُجدت."
        autoComplete="off"
        dir="ltr"
        maxLength={50}
        required
      />
    </div>
  )
}

function IndividualContact({ form, update, errors, countries, loginEmail }: SectionProps<IndividualForm> & { loginEmail: string }) {
  return (
    <div className="form-grid">
      <SelectField id="individual-residence_country" label="دولة الإقامة" value={form.residence_country} onChange={(value) => update('residence_country', value)} options={countryOptions(countries)} placeholder="اختر دولة الإقامة" error={errors['individual.residence_country']} autoComplete="country" />
      <div className="form-grid__full">
        <TextAreaField id="individual-address" label="العنوان" value={form.address} onChange={(value) => update('address', value)} error={errors['individual.address']} hint="المدينة والحي والشارع، أو أي عنوان يمكن الوصول إليك فيه." maxLength={500} autoComplete="street-address" />
      </div>
      <PhoneField name="individual.primary_phone" label="رقم التواصل الأساسي" value={form.primary_phone} onChange={(value) => update('primary_phone', value)} countries={countries} errors={errors} required />
      <PhoneField name="individual.secondary_phone" label="رقم التواصل الاحتياطي" value={form.secondary_phone} onChange={(value) => update('secondary_phone', value)} countries={countries} errors={errors} />
      <div className="form-grid__full">
        <TextField
          id="individual-contact_email"
          label="البريد الإلكتروني للتواصل"
          type="email"
          value={form.contact_email}
          onChange={(value) => update('contact_email', value)}
          error={errors['individual.contact_email']}
          hint={form.contact_email.trim() === loginEmail ? 'اقترحنا بريد حسابك، ويمكنك تغييره. لا يغيّر ذلك بريد تسجيل الدخول.' : 'لا يغيّر هذا البريد بريد تسجيل الدخول إلى حسابك.'}
          autoComplete="email"
          maxLength={255}
          required
        />
      </div>
      <SocialLinksField links={form.social_links} onChange={(links) => update('social_links', links)} errors={errors} />
    </div>
  )
}

function InstitutionDetails({ form, update, errors, countries }: SectionProps<InstitutionForm>) {
  return (
    <div className="form-grid">
      <div className="form-grid__full">
        <TextField id="institution-name" label="اسم المؤسسة" value={form.name} onChange={(value) => update('name', value)} error={errors['institution.name']} hint="الاسم الرسمي كما في الترخيص." autoComplete="organization" dir="auto" maxLength={255} required />
      </div>
      <SelectField id="institution-country" label="الدولة" value={form.country} onChange={(value) => update('country', value)} options={countryOptions(countries)} placeholder="اختر الدولة" error={errors['institution.country']} />
      <TextField id="institution-activity_type" label="نوع النشاط المؤسسي" value={form.activity_type} onChange={(value) => update('activity_type', value)} error={errors['institution.activity_type']} hint="مثلاً: جمعية أهلية، مؤسسة ثقافية، شركة." autoComplete="off" maxLength={150} required />
      <TextField
        id="institution-license_number"
        label="رقم الترخيص"
        value={form.license_number}
        onChange={(value) => update('license_number', value)}
        error={errors['institution.license_number']}
        hint="كما هو في وثيقة الترخيص، مع الأصفار والفواصل."
        autoComplete="off"
        dir="ltr"
        maxLength={50}
        required
      />
    </div>
  )
}

function InstitutionContact({ form, update, errors, countries }: SectionProps<InstitutionForm>) {
  return (
    <div className="form-grid">
      <p className="form-grid__full wizard__intro">صاحب هذا الحساب هو الشخص المتقدم بالنيابة عن المؤسسة، ويبقى اسمه وبريد دخوله كما هما.</p>
      <TextField id="institution-representative_name" label="اسم الشخص المتقدم بالنيابة عن المؤسسة" value={form.representative_name} onChange={(value) => update('representative_name', value)} error={errors['institution.representative_name']} autoComplete="name" dir="auto" maxLength={150} required />
      <TextField id="institution-representative_title" label="الصفة الوظيفية" value={form.representative_title} onChange={(value) => update('representative_title', value)} error={errors['institution.representative_title']} hint="مثلاً: المدير التنفيذي، منسّق البرامج." autoComplete="organization-title" maxLength={150} required />
      <PhoneField name="institution.phone" label="رقم تواصل المؤسسة" value={form.phone} onChange={(value) => update('phone', value)} countries={countries} errors={errors} required />
      <TextField id="institution-email" label="البريد الإلكتروني للمؤسسة" type="email" value={form.email} onChange={(value) => update('email', value)} error={errors['institution.email']} autoComplete="off" maxLength={255} required />
      <div className="form-grid__full">
        <TextField id="institution-website" label="الموقع الإلكتروني" type="url" value={form.website} onChange={(value) => update('website', value)} error={errors['institution.website']} hint="الرابط الكامل، مثل https://www.example.org" autoComplete="url" maxLength={500} optional />
      </div>
      <SocialLinksField links={form.social_links} onChange={(links) => update('social_links', links)} errors={errors} />
    </div>
  )
}

function ReviewStep({
  form,
  countries,
  requirements,
  errors,
  onEdit,
  setDeclaration,
  emailVerified,
}: {
  form: FormState
  countries: Country[]
  requirements: Requirements
  errors: Errors
  onEdit: (step: StepId) => void
  setDeclaration: (key: keyof FormState['declarations'], value: boolean) => void
  emailVerified: boolean
}) {
  return (
    <>
      <div className="lock-warning" role="note">
        <svg className="lock-warning__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
          <rect x="5" y="10.5" width="14" height="10" rx="1.5" />
          <path d="M8 10.5V7.5a4 4 0 0 1 8 0v3" />
        </svg>
        <p>{LOCK_WARNING}</p>
      </div>

      <ProfileSummary groups={summaryFromForm(form, countries)} onEdit={onEdit} headingLevel={3} />

      <fieldset className="declarations">
        <legend className="declarations__title">الموافقات والإقرارات</legend>
        {(['privacy_policy', 'terms_of_use'] as PolicyCode[]).map((code) => {
          const policy = requirements.policies[code]
          if (!policy) return null
          return (
            <div key={code} className="declaration">
              <p className="declaration__link">
                <a href={`/policies/${POLICY_SLUGS[code]}?version=${policy.version_number}`} target="_blank" rel="noopener">
                  اقرأ {POLICY_NAMES[code]} (الإصدار {policy.version_number})
                </a>{' '}
                <span className="field__hint">تُفتح في نافذة جديدة، ويبقى ما أدخلته هنا.</span>
              </p>
              <CheckboxField id={`declaration-${code}`} checked={form.declarations[code]} onChange={(checked) => setDeclaration(code, checked)} error={errors[`accepted_policies.${code}`]}>
                قرأت {POLICY_NAMES[code]} (الإصدار {policy.version_number}) وأوافق عليها.
              </CheckboxField>
            </div>
          )
        })}
        <CheckboxField id="declaration-data_accuracy" checked={form.declarations.data_accuracy} onChange={(checked) => setDeclaration('data_accuracy', checked)} error={errors['declarations.data_accuracy']}>
          أُقرّ بأن البيانات التي أدخلتها صحيحة وكاملة على حدّ علمي.
        </CheckboxField>
        {form.profileType === 'institution' && (
          <CheckboxField id="declaration-representation" checked={form.declarations.representation} onChange={(checked) => setDeclaration('representation', checked)} error={errors['declarations.representation']}>
            أُقرّ بأنني مخوَّل بتمثيل المؤسسة المذكورة وتقديم بياناتها.
          </CheckboxField>
        )}
      </fieldset>

      {!emailVerified && <VerifyEmailNotice context="form" />}
    </>
  )
}
