import { useCallback, useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import type { User } from '../../../auth/useAuth'
import { FormAlert, TextField } from '../../../components/forms/FormControls'
import { api, errorMessage } from '../../../lib/api'
import { usePageTitle } from '../../auth/usePageTitle'
import { formatDamascusDateTime } from '../../candidate/candidateApi'
import { useAccountFailure } from '../../candidate/useCandidateRequests'
import type { AdminCycle, CycleSchedule } from '../adminApi'
import { cycleLabel } from '../adminApi'

type FormState = {
  nomination_opens_on: string
  nomination_closes_on: string
  judging_opens_on: string
  judging_closes_on: string
}

/**
 * «المواعيد»: the registration window and the judging window of the
 * selected cycle. Dates are calendar days in the cycle timezone
 * (Asia/Damascus); the closing day is included in full.
 */
export function SchedulePage({ user, cycle }: { user: User; cycle: AdminCycle | null }) {
  usePageTitle('المواعيد')
  const handleAccountFailure = useAccountFailure()
  const [schedule, setSchedule] = useState<CycleSchedule | null>(null)
  const [form, setForm] = useState<FormState | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [formError, setFormError] = useState<string | null>(null)
  const [saved, setSaved] = useState(false)
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    if (!cycle) return
    setLoadError(null)
    try {
      const response = await api.get<{ data: CycleSchedule }>(`/api/admin/cycles/${cycle.id}/schedule`)
      setSchedule(response.data)
      setForm({
        nomination_opens_on: response.data.nomination.opens_on ?? '',
        nomination_closes_on: response.data.nomination.closes_on ?? '',
        judging_opens_on: response.data.judging.opens_on ?? '',
        judging_closes_on: response.data.judging.closes_on ?? '',
      })
    } catch (error) {
      setLoadError(errorMessage(error))
    }
  }, [cycle])

  useEffect(() => {
    // Fetching on mount: the state is set once the request settles.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load()
  }, [load])

  if (!cycle) {
    return (
      <div className="admin-page">
        <h1 className="admin-page__title">المواعيد</h1>
        <FormAlert tone="info">
          <p>لا توجد دورة جائزة مُعدّة بعد. تُضبط المواعيد لدورة محدّدة.</p>
        </FormAlert>
      </div>
    )
  }

  const selectedCycle = cycle

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!form || busy) return
    setBusy(true)
    setFormError(null)
    setSaved(false)
    try {
      const response = await api.post<{ data: CycleSchedule }>(`/api/admin/cycles/${selectedCycle.id}/schedule`, {
        ...form,
        account_context: user.account_context,
      })
      setSchedule(response.data)
      setForm({
        nomination_opens_on: response.data.nomination.opens_on ?? '',
        nomination_closes_on: response.data.nomination.closes_on ?? '',
        judging_opens_on: response.data.judging.opens_on ?? '',
        judging_closes_on: response.data.judging.closes_on ?? '',
      })
      setSaved(true)
    } catch (error) {
      if (handleAccountFailure(error)) return
      setFormError(errorMessage(error))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="admin-page">
      <header className="admin-page__header">
        <p className="admin-page__eyebrow">إدارة الجائزة</p>
        <h1 className="admin-page__title">المواعيد</h1>
        <p className="admin-page__intro">
          مواعيد الترشح والتحكيم لدورة <strong>{cycleLabel(cycle)}</strong>. التواريخ بتوقيت {schedule?.cycle.timezone ?? 'Asia/Damascus'}، ويوم الانتهاء مشمول بالكامل. فترة التحكيم تبدأ بعد انتهاء التسجيل.
        </p>
      </header>

      {loadError && (
        <FormAlert tone="error">
          <p>{loadError}</p>
          <p>
            <button type="button" className="button button--secondary" onClick={() => void load()}>
              إعادة المحاولة
            </button>
          </p>
        </FormAlert>
      )}

      {!loadError && !form && (
        <p className="admin-loading" role="status">
          جارٍ تحميل المواعيد…
        </p>
      )}

      {form && schedule && (
        <form className="admin-form" onSubmit={(event) => void onSubmit(event)} noValidate>
          {saved && (
            <FormAlert tone="success">
              <p>حُفظت المواعيد.</p>
            </FormAlert>
          )}
          {formError && (
            <FormAlert tone="error">
              <p>{formError}</p>
            </FormAlert>
          )}

          <section className="admin-schedule-block" aria-labelledby="nomination-window">
            <h2 id="nomination-window" className="admin-section-title">
              {schedule.nomination.name}
            </h2>
            <p className="admin-muted">
              الحالة الآن: {schedule.nomination.open ? 'مفتوحة للترشح' : 'مغلقة'}
              {schedule.nomination.opens_at ? ` · من ${formatDamascusDateTime(schedule.nomination.opens_at)}` : ''}
              {schedule.nomination.closes_at ? ` إلى ${formatDamascusDateTime(schedule.nomination.closes_at)}` : ''}.
            </p>
            <div className="admin-schedule-fields">
              <TextField
                id="nomination_opens_on"
                label="تاريخ بدء الترشح"
                type="date"
                value={form.nomination_opens_on}
                onChange={(value) => setForm((current) => (current ? { ...current, nomination_opens_on: value } : current))}
                required
                autoComplete="off"
                dir="ltr"
              />
              <TextField
                id="nomination_closes_on"
                label="تاريخ انتهاء التسجيل"
                type="date"
                value={form.nomination_closes_on}
                onChange={(value) => setForm((current) => (current ? { ...current, nomination_closes_on: value } : current))}
                required
                autoComplete="off"
                dir="ltr"
              />
            </div>
          </section>

          <section className="admin-schedule-block" aria-labelledby="judging-window">
            <h2 id="judging-window" className="admin-section-title">
              {schedule.judging.name}
            </h2>
            <p className="admin-muted">
              الحالة الآن: {schedule.judging.open ? 'مفتوحة للتحكيم' : 'مغلقة'}
              {schedule.judging.opens_at ? ` · من ${formatDamascusDateTime(schedule.judging.opens_at)}` : ''}
              {schedule.judging.closes_at ? ` إلى ${formatDamascusDateTime(schedule.judging.closes_at)}` : ''}.
            </p>
            <div className="admin-schedule-fields">
              <TextField
                id="judging_opens_on"
                label="تاريخ بدء التحكيم"
                type="date"
                value={form.judging_opens_on}
                onChange={(value) => setForm((current) => (current ? { ...current, judging_opens_on: value } : current))}
                required
                autoComplete="off"
                dir="ltr"
              />
              <TextField
                id="judging_closes_on"
                label="تاريخ انتهاء التحكيم"
                type="date"
                value={form.judging_closes_on}
                onChange={(value) => setForm((current) => (current ? { ...current, judging_closes_on: value } : current))}
                required
                autoComplete="off"
                dir="ltr"
              />
            </div>
          </section>

          <button type="submit" className="button button--primary" disabled={busy}>
            {busy ? 'جارٍ الحفظ…' : 'حفظ المواعيد'}
          </button>
        </form>
      )}
    </div>
  )
}
