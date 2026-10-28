import { useCallback, useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import type { User } from '../../auth/useAuth'
import { FieldLabel, FieldMessages, FormAlert, TextField } from '../../components/forms/FormControls'
import { CheckboxField } from '../candidate/basic/fields'
import { ApiError, api, errorMessage } from '../../lib/api'
import { Link } from '../../lib/router'
import { useLeaveWarning, useRouter } from '../../lib/useRouter'
import { usePageTitle } from '../auth/usePageTitle'
import { contextHeader, formatDamascusDateTime } from '../candidate/candidateApi'
import { AttachmentList, LinkList } from '../candidate/documentation/AttachmentList'
import type { AttachmentKind } from '../candidate/documentation/documentationApi'
import { SECTION_TITLES } from '../candidate/documentation/documentationApi'
import { nominationSummaryFromDetails } from '../candidate/nomination/nominationSummaries'
import { ProfileSummary } from '../candidate/basic/ProfileSummary'
import { cycleLabel } from '../admin/adminApi'
import type { JudgeProject } from './judgeApi'
import { judgeFileUrl } from './judgeApi'

/** One assigned project: the submitted work, then this judge's mark. */
export function ProjectPage({ user, applicationId }: { user: User; applicationId: number }) {
  usePageTitle('تحكيم مشروع')
  const { navigate } = useRouter()
  const [project, setProject] = useState<JudgeProject | null>(null)
  const [error, setError] = useState<{ message: string; missing: boolean } | null>(null)
  const [score, setScore] = useState('')
  const [notes, setNotes] = useState('')
  const [recommendations, setRecommendations] = useState('')
  const [excluded, setExcluded] = useState(false)
  const [fieldErrors, setFieldErrors] = useState<{ score?: string; notes?: string; recommendations?: string }>({})
  const [sending, setSending] = useState(false)
  const [formError, setFormError] = useState<string | null>(null)

  const load = useCallback(async () => {
    setError(null)
    try {
      setProject((await api.get<{ data: JudgeProject }>(`/api/judge/projects/${applicationId}`, contextHeader(user))).data)
    } catch (failure) {
      setError({ message: errorMessage(failure), missing: failure instanceof ApiError && failure.status === 404 })
    }
  }, [applicationId, user])

  useEffect(() => {
    // Fetching on mount: the state is set once the request settles.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load()
  }, [load])

  const pending = project?.evaluation.status !== 'sent'
  const dirty = score.trim() !== '' || notes.trim() !== '' || recommendations.trim() !== '' || excluded
  useLeaveWarning(pending && dirty ? 'علامتك لم تُرسل إلى الإدارة بعد. هل تغادر الصفحة؟' : null)

  const onSubmit = async (event: FormEvent) => {
    event.preventDefault()
    if (sending || !pending) return
    const next = {
      score: score.trim() === '' ? 'ضع العلامة من 100.' : undefined,
      notes: notes.trim() === '' ? 'اكتب الملاحظات قبل الإرسال.' : undefined,
      recommendations: recommendations.trim() === '' ? 'اكتب التوصيات قبل الإرسال.' : undefined,
    }
    setFieldErrors(next)
    if (next.score || next.notes || next.recommendations) return

    setSending(true)
    setFormError(null)
    try {
      const saved = await api.post<{ data: JudgeProject }>(`/api/judge/projects/${applicationId}/evaluation`, {
        account_context: user.account_context,
        score: score.trim(),
        notes: notes.trim(),
        recommendations: recommendations.trim(),
        excluded,
      })
      setProject(saved.data)
      setScore('')
      setNotes('')
      setRecommendations('')
      setExcluded(false)
    } catch (failure) {
      if (failure instanceof ApiError && failure.status === 422) {
        setFieldErrors({
          score: failure.errors.score?.[0],
          notes: failure.errors.notes?.[0],
          recommendations: failure.errors.recommendations?.[0],
        })
      }
      setFormError(errorMessage(failure))
      if (failure instanceof ApiError && failure.code === 'already_sent') void load()
    } finally {
      setSending(false)
    }
  }

  const back = (
    <p className="candidate-page__intro">
      <Link href="/judge">العودة إلى المشاريع</Link>
    </p>
  )

  if (error) {
    return (
      <div className="candidate-page">
        {back}
        <FormAlert tone={error.missing ? 'info' : 'error'}>
          <p>{error.message}</p>
          {!error.missing && (
            <p>
              <button type="button" className="button button--secondary" onClick={() => void load()}>
                إعادة المحاولة
              </button>
            </p>
          )}
        </FormAlert>
      </div>
    )
  }

  if (!project) {
    return (
      <div className="candidate-page">
        <p className="candidate-loading" role="status">
          جارٍ تحميل المشروع…
        </p>
      </div>
    )
  }

  const sent = project.evaluation.status === 'sent'
  const title = project.nomination_details?.title ?? project.summary.reference_number

  return (
    <div className="candidate-page">
      {back}
      <header className="candidate-page__header">
        <p className="eyebrow">
          {project.summary.domain?.name ?? 'المحور'}
          {project.summary.subcategory ? ` · ${project.summary.subcategory.name}` : ''}
        </p>
        <h1 className="candidate-page__title">
          <bdi>{title}</bdi>
        </h1>
        <p className="candidate-page__intro">
          <bdi dir="ltr">{project.summary.reference_number}</bdi>
          {' · '}
          {cycleLabel(project.summary.cycle)}
          {' · أُرسل '}
          {formatDamascusDateTime(project.summary.submitted_at)} بتوقيت دمشق
        </p>
      </header>

      {project.nomination_details && (
        <section className="candidate-section" aria-labelledby="judge-work">
          <h2 id="judge-work" className="candidate-section__title">
            بيانات العمل
          </h2>
          <ProfileSummary groups={nominationSummaryFromDetails(project.nomination_details)} headingLevel={3} />
        </section>
      )}

      <section className="candidate-section" aria-labelledby="judge-files">
        <h2 id="judge-files" className="candidate-section__title">
          ملفات العمل
        </h2>
        {(['explanatory', 'supporting', 'video'] as AttachmentKind[]).map((kind) => (
          <div key={kind} className="summary-group">
            <h3 className="summary-group__title">{SECTION_TITLES[kind]}</h3>
            <AttachmentList
              attachments={project.documentation.attachments.filter((item) => item.kind === kind)}
              urlFor={(attachment) => judgeFileUrl(user, project.summary.id, attachment)}
              emptyText="لم يُضف شيء."
            />
          </div>
        ))}
        <div className="summary-group">
          <h3 className="summary-group__title">روابط توثيق إضافية</h3>
          <LinkList links={project.documentation.links} emptyText="لم تُضف روابط." />
        </div>
      </section>

      <section className="candidate-section" aria-labelledby="judge-score">
        <h2 id="judge-score" className="candidate-section__title">
          علامتك
        </h2>
        {sent ? (
          <FormAlert tone="success">
            <p>
              أُرسلت إلى الإدارة: {project.evaluation.score} من 100
              {project.evaluation.sent_at ? `، في ${formatDamascusDateTime(project.evaluation.sent_at)} بتوقيت دمشق` : ''}.
            </p>
            {project.evaluation.excluded && <p>استُبعد المشارك.</p>}
            {project.evaluation.notes && <p>الملاحظات: {project.evaluation.notes}</p>}
            {project.evaluation.recommendations && <p>التوصيات: {project.evaluation.recommendations}</p>}
          </FormAlert>
        ) : (
          <form onSubmit={(event) => void onSubmit(event)} noValidate>
            {formError && (
              <FormAlert tone="error">
                <p>{formError}</p>
              </FormAlert>
            )}
            <TextField
              id="score"
              label="العلامة من 100"
              value={score}
              onChange={(value) => {
                setScore(value)
                setFieldErrors((current) => ({ ...current, score: undefined }))
              }}
              error={fieldErrors.score}
              required
              autoComplete="off"
              inputMode="decimal"
              dir="ltr"
            />
            <div className="field">
              <FieldLabel htmlFor="notes" label="الملاحظات" required />
              <textarea
                id="notes"
                name="notes"
                className="field__input field__textarea"
                value={notes}
                onChange={(event) => {
                  setNotes(event.target.value)
                  setFieldErrors((current) => ({ ...current, notes: undefined }))
                }}
                required
                maxLength={4000}
                aria-invalid={fieldErrors.notes ? true : undefined}
              />
              <FieldMessages id="notes" error={fieldErrors.notes} />
            </div>
            <div className="field">
              <FieldLabel htmlFor="recommendations" label="التوصيات" required />
              <textarea
                id="recommendations"
                name="recommendations"
                className="field__input field__textarea"
                value={recommendations}
                onChange={(event) => {
                  setRecommendations(event.target.value)
                  setFieldErrors((current) => ({ ...current, recommendations: undefined }))
                }}
                required
                maxLength={4000}
                aria-invalid={fieldErrors.recommendations ? true : undefined}
              />
              <FieldMessages id="recommendations" error={fieldErrors.recommendations} />
            </div>
            <CheckboxField id="excluded" checked={excluded} onChange={setExcluded}>
              استبعاد المشارك
            </CheckboxField>
            <button type="submit" className="button button--primary" disabled={sending}>
              {sending ? 'جارٍ الإرسال…' : 'إرسال إلى الإدارة'}
            </button>
          </form>
        )}
      </section>
      <p className="candidate-page__intro">
        <button type="button" className="button button--secondary button--small" onClick={() => navigate('/judge')}>
          العودة إلى المشاريع
        </button>
      </p>
    </div>
  )
}
