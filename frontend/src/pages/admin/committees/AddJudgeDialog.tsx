import { useId, useRef, useState } from 'react'
import type { FormEvent } from 'react'
import type { User } from '../../../auth/useAuth'
import { FormAlert, TextField } from '../../../components/forms/FormControls'
import { ApiError, api, errorMessage } from '../../../lib/api'
import type { AccountSummary, AppointmentResult, CommitteesResponse, DomainCode, DomainCommittee } from '../adminApi'
import { AdminDialog } from './AdminDialog'
import { existingAppointment, isCurrentLookup, normalizeEmail } from './judgeLookup'
import { useLocale } from '../../../i18n/useLocale'

type Step = { kind: 'email' } | { kind: 'new' } | { kind: 'existing'; account: AccountSummary }

/**
 * Adds a judge to the committee of one MAIN domain (no subcategory). First
 * the email: an existing account is shown as it is and appointed only on
 * explicit confirmation (its name, password, verification and sign-in
 * methods are never changed); otherwise a new account is created with the
 * name and optional phone, and its owner receives the password-reset link
 * to set a password.
 *
 * A confirmation is bound to the account shown: an answer to an older
 * lookup, or one for an email the field no longer holds, is dropped;
 * editing the email discards the shown account; and the appointment sends
 * that account's own email and binding (the server refuses it if the email
 * no longer names exactly that account).
 */
export function AddJudgeDialog({
  open,
  user,
  cycleId,
  committees,
  initialDomain,
  onClose,
  onDone,
  onCreateCommittee,
}: {
  open: boolean
  user: User
  cycleId: number
  committees: DomainCommittee[]
  initialDomain: DomainCode
  onClose: () => void
  onDone: (response: CommitteesResponse & { appointment: AppointmentResult }) => void
  onCreateCommittee: (domain: DomainCode) => void
}) {
  const { t } = useLocale()
  const [domain, setDomain] = useState<DomainCode>(initialDomain)
  const [email, setEmail] = useState('')
  const [name, setName] = useState('')
  const [phone, setPhone] = useState('')
  const [specialty, setSpecialty] = useState('')
  const [step, setStep] = useState<Step>({ kind: 'email' })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [alert, setAlert] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [lookingUp, setLookingUp] = useState(false)
  const latestLookup = useRef(0)
  const currentEmail = useRef('')
  const domainId = useId()
  const target = committees.find((item) => item.domain.code === domain)
  const committee = target?.committee ?? null
  const blocked = committee === null ? 'missing' : committee.status !== 'draft' ? 'approved' : null

  const fail = (error: unknown) => {
    if (error instanceof ApiError && error.status === 422) {
      setErrors(Object.fromEntries(Object.entries(error.errors).map(([field, list]) => [field, list[0]])))
      setAlert(t('صحّح الحقول المشار إليها.'))
    } else {
      setAlert(errorMessage(error))
    }
  }

  /** A new email: any shown account, confirmation or pending lookup no longer applies. */
  const changeEmail = (value: string) => {
    setEmail(value)
    currentEmail.current = value
    latestLookup.current += 1
    setLookingUp(false)
    setStep({ kind: 'email' })
    setAlert(null)
  }

  const lookup = async (event: FormEvent) => {
    event.preventDefault()
    const requestId = ++latestLookup.current
    const requested = email
    setLookingUp(true)
    setErrors({})
    setAlert(null)
    try {
      const response = await api.post<{ data: { account: AccountSummary | null } }>(`/api/admin/cycles/${cycleId}/judges/lookup`, {
        email: requested,
        account_context: user.account_context,
      })
      if (!isCurrentLookup(requestId, latestLookup.current, requested, currentEmail.current)) return
      setStep(response.data.account ? { kind: 'existing', account: response.data.account } : { kind: 'new' })
    } catch (error) {
      if (isCurrentLookup(requestId, latestLookup.current, requested, currentEmail.current)) fail(error)
    } finally {
      if (requestId === latestLookup.current) setLookingUp(false)
    }
  }

  const appoint = async (event: FormEvent) => {
    event.preventDefault()
    if (lookingUp || step.kind === 'email') return
    const target =
      step.kind === 'existing'
        ? { ...existingAppointment(step.account), name: null, phone: null }
        : { existing: false, email, name, phone: phone.trim() ? phone : null }
    setBusy(true)
    setErrors({})
    setAlert(null)
    try {
      onDone(
        await api.post<CommitteesResponse & { appointment: AppointmentResult }>(`/api/admin/cycles/${cycleId}/judges`, {
          domain,
          ...target,
          specialty: specialty.trim() || null,
          account_context: user.account_context,
        }),
      )
    } catch (error) {
      const shown = error instanceof ApiError ? (error.payload.account as unknown as AccountSummary | undefined) : undefined
      if (error instanceof ApiError && error.code === 'account_exists' && shown && shown.email === normalizeEmail(currentEmail.current)) {
        // Someone created this email meanwhile: show that account to confirm instead.
        setStep({ kind: 'existing', account: shown })
      } else if (error instanceof ApiError && (error.code === 'account_changed' || error.code === 'account_missing')) {
        // The shown account is no longer what this email names: look it up again.
        latestLookup.current += 1
        setStep({ kind: 'email' })
      }
      fail(error)
    } finally {
      setBusy(false)
    }
  }

  return (
    <AdminDialog open={open} title={t('إضافة حكَم')} titleId="add-judge-title" onClose={onClose} wide>
      <form className="admin-dialog__body admin-form" onSubmit={step.kind === 'email' ? lookup : appoint} noValidate>
        {alert && <FormAlert tone="error">{<p>{alert}</p>}</FormAlert>}

        <div className="field">
          <label className="field__label" htmlFor={domainId}>
            {t('المجال الرئيسي')} <span className="field__required">{t('(مطلوب)')}</span>
          </label>
          <select id={domainId} className="field__input field__select" value={domain} onChange={(event) => setDomain(event.target.value as DomainCode)} required>
            {committees.map((item) => (
              <option key={item.domain.code} value={item.domain.code}>
                {item.domain.name}
              </option>
            ))}
          </select>
          <p className="field__hint">{t('يُصنَّف الحكَم بالمجال الرئيسي فقط، ويعمل في لجنة هذا المجال لكل فئاته الفرعية.')}</p>
        </div>

        {blocked === 'missing' && (
          <div className="admin-inline-notice">
            <p>{t('لا توجد لجنة تحكيم لمجال «{domain}» في هذه الدورة بعد. أنشئها أولاً، ثم أضف إليها الحكام.', { domain: target?.domain.name ?? '' })}</p>
            <button type="button" className="button button--secondary button--small" onClick={() => onCreateCommittee(domain)}>
              {t('إنشاء اللجنة')}
            </button>
          </div>
        )}
        {blocked === 'approved' && (
          <div className="admin-inline-notice">
            <p>{t('لجنة هذا المجال معتمدة. أعدها إلى المسودة أولاً لتغيير أعضائها.')}</p>
          </div>
        )}

        <TextField
          id="judge-email"
          label={t('البريد الإلكتروني')}
          type="email"
          value={email}
          onChange={changeEmail}
          error={errors.email}
          required
          autoComplete="off"
        />

        {step.kind === 'existing' && (
          <div className="admin-account" aria-live="polite">
            <p className="admin-account__title">{t('يوجد حساب مسجّل بهذا البريد')}</p>
            <dl className="admin-facts admin-facts--compact">
              <div>
                <dt>{t('الاسم')}</dt>
                <dd>
                  <bdi>{step.account.name}</bdi>
                </dd>
              </div>
              <div>
                <dt>{t('البريد')}</dt>
                <dd>
                  <bdi dir="ltr">{step.account.email}</bdi>
                </dd>
              </div>
              <div>
                <dt>{t('حالة الحساب')}</dt>
                <dd>
                  {step.account.active ? t('نشط') : t('معطّل')}
                  {' · '}
                  {step.account.email_verified ? t('البريد مؤكَّد') : t('البريد غير مؤكَّد')}
                </dd>
              </div>
              {step.account.judging_domain && (
                <div>
                  <dt>{t('عضوية حالية')}</dt>
                  <dd>{t('حكَم في لجنة «{domain}» في هذه الدورة', { domain: step.account.judging_domain ?? '' })}</dd>
                </div>
              )}
              {step.account.submitted_in_cycle && (
                <div>
                  <dt>{t('تعارض مصالح')}</dt>
                  <dd>{t('له مشاركة مرسلة في هذه الدورة')}</dd>
                </div>
              )}
            </dl>
            {!step.account.active ? (
              <p className="admin-account__warning">{t('هذا الحساب معطّل، فلا يُعيَّن حكَماً.')}</p>
            ) : (
              <p className="admin-muted">{t('سيُعيَّن الحساب كما هو: لا يتغيّر اسمه ولا كلمة مروره ولا تأكيد بريده ولا طرق دخوله.')}</p>
            )}
          </div>
        )}

        {step.kind === 'new' && (
          <>
            <p className="admin-muted">{t('لا يوجد حساب بهذا البريد. سيُنشأ حساب جديد بلا كلمة مرور، ويصل إلى البريد رابط لتعيين كلمة المرور.')}</p>
            <TextField id="judge-name" label={t('الاسم الكامل')} value={name} onChange={setName} error={errors.name} required autoComplete="off" maxLength={255} />
            <TextField
              id="judge-phone"
              label={t('رقم الهاتف')}
              type="tel"
              value={phone}
              onChange={setPhone}
              error={errors.phone}
              optional
              hint={t('بصيغته الدولية، مثل ‎+963 944 123 456')}
              autoComplete="off"
            />
          </>
        )}

        {step.kind !== 'email' && (
          <TextField id="judge-specialty" label={t('التخصص')} value={specialty} onChange={setSpecialty} error={errors.specialty} optional autoComplete="off" maxLength={150} />
        )}

        <div className="admin-dialog__actions">
          <button type="button" className="button button--secondary" onClick={onClose}>
            {t('إلغاء')}
          </button>
          {step.kind === 'email' ? (
            <button type="submit" className="button button--primary" disabled={lookingUp || !email.trim()} aria-busy={lookingUp || undefined}>
              {lookingUp ? t('جارٍ التحقق…') : t('متابعة')}
            </button>
          ) : (
            <button
              type="submit"
              className="button button--primary"
              disabled={busy || lookingUp || blocked !== null || (step.kind === 'existing' && !step.account.active)}
              aria-busy={busy || undefined}
            >
              {busy ? t('جارٍ الحفظ…') : step.kind === 'existing' ? t('تعيين هذا الحساب حكَماً') : t('إنشاء الحساب وتعيينه حكَماً')}
            </button>
          )}
        </div>
      </form>
    </AdminDialog>
  )
}
