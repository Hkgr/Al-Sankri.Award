import { useCallback, useEffect, useState } from 'react'
import type { ReactNode } from 'react'
import { useAuth } from '../../../auth/useAuth'
import type { User } from '../../../auth/useAuth'
import { FormAlert } from '../../../components/forms/FormControls'
import { ApiError, api, errorMessage } from '../../../lib/api'
import { Link } from '../../../lib/router'
import { useRouter } from '../../../lib/useRouter'
import { usePageTitle } from '../../auth/usePageTitle'
import { ProfileSummary } from '../../candidate/basic/ProfileSummary'
import { summaryFromProfile } from '../../candidate/basic/summaries'
import { contextHeader, formatDamascusDateTime, POLICY_NAMES } from '../../candidate/candidateApi'
import type { PolicyCode } from '../../candidate/candidateApi'
import { AttachmentList, LinkList } from '../../candidate/documentation/AttachmentList'
import type { AttachmentKind } from '../../candidate/documentation/documentationApi'
import { SECTION_TITLES } from '../../candidate/documentation/documentationApi'
import { nominationSummaryFromDetails } from '../../candidate/nomination/nominationSummaries'
import type { ApplicationDetail } from '../adminApi'
import { adminFileUrl, cycleLabel, TABLE_PARAMS } from '../adminApi'
import { ExportMenu } from '../ExportMenu'
import { saveFile } from '../saveFile'
import { StatusBadge } from './StatusBadge'

/** «تنزيل بطاقة الترشح PDF»: the submitted application as a server-made PDF. */
function CardDownload({ user, applicationId }: { user: User; applicationId: number }) {
  const [busy, setBusy] = useState(false)
  const [failure, setFailure] = useState<string | null>(null)

  const download = async () => {
    setBusy(true)
    setFailure(null)
    try {
      const file = await api.download(`/api/admin/applications/${applicationId}/card`, contextHeader(user))
      saveFile(file.blob, file.filename)
    } catch (error) {
      setFailure(errorMessage(error))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="admin-export">
      <button type="button" className="button button--primary button--small" onClick={() => void download()} disabled={busy} aria-busy={busy || undefined}>
        {busy ? 'جارٍ إعداد البطاقة…' : 'تنزيل بطاقة الترشح PDF'}
      </button>
      {failure && (
        <p className="admin-export__error" role="alert">
          {failure}
        </p>
      )}
    </div>
  )
}

/** The table address to return to: only the table's own query values are kept. */
function backAddress(from: string | null): string {
  if (!from?.startsWith('/admin/applications')) return '/admin/applications'
  const given = new URLSearchParams(from.split('?')[1] ?? '')
  const kept = new URLSearchParams()
  for (const name of TABLE_PARAMS) {
    const value = given.get(name)
    if (value) kept.set(name, value)
  }
  const query = kept.toString()
  return query ? `/admin/applications?${query}` : '/admin/applications'
}

function Section({ id, title, children }: { id: string; title: string; children: ReactNode }) {
  return (
    <section className="admin-section" aria-labelledby={id}>
      <h2 id={id} className="admin-section__title">
        {title}
      </h2>
      {children}
    </section>
  )
}

/**
 * One submitted participation, read-only, as its immutable submitted
 * version holds it: the summary, Stage 1, Stage 2, Stage 3 with protected
 * downloads and native video playback, and the recorded declarations.
 */
export function ApplicationPage({ applicationId }: { applicationId: number }) {
  const { user } = useAuth()
  usePageTitle('ملف ترشح')
  const { location } = useRouter()
  const back = backAddress(new URLSearchParams(location.search).get('from'))
  const [detail, setDetail] = useState<ApplicationDetail | null>(null)
  const [error, setError] = useState<{ message: string; missing: boolean } | null>(null)

  const load = useCallback(async () => {
    setError(null)
    try {
      setDetail((await api.get<{ data: ApplicationDetail }>(`/api/admin/applications/${applicationId}`)).data)
    } catch (failure) {
      setError({ message: errorMessage(failure), missing: failure instanceof ApiError && failure.status === 404 })
    }
  }, [applicationId])

  useEffect(() => {
    // Fetching on mount: the state is set once the request settles.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load()
  }, [load])

  const backLink = (
    <p className="admin-back">
      <Link href={back}>العودة إلى جدول ملفات الترشح</Link>
    </p>
  )

  if (error) {
    return (
      <div className="admin-page">
        {backLink}
        <FormAlert tone={error.missing ? 'info' : 'error'}>
          <p>{error.message}</p>
          {!error.missing && (
            <p>
              <button type="button" className="button button--secondary button--small" onClick={() => void load()}>
                إعادة المحاولة
              </button>
            </p>
          )}
        </FormAlert>
      </div>
    )
  }

  if (!detail) {
    return (
      <div className="admin-page">
        {backLink}
        <p className="admin-loading" role="status">
          جارٍ تحميل ملف الترشح…
        </p>
      </div>
    )
  }

  const { summary, documentation, declarations } = detail
  const pendingScan = documentation.attachments.some((item) => item.scan_status !== 'clean')

  return (
    <div className="admin-page">
      {backLink}
      <header className="admin-page__header admin-page__header--actions">
        <div>
          <p className="admin-page__eyebrow">ملف ترشح مرسل · للعرض فقط</p>
          <h1 className="admin-page__title">
            <bdi>{detail.nomination_details?.title ?? summary.reference_number}</bdi>
          </h1>
        </div>
        {user?.admin_permissions.includes('reports.export') && <CardDownload user={user} applicationId={summary.id} />}
      </header>

      <Section id="admin-summary" title="ملخص الإرسال">
        <dl className="admin-facts">
          <div>
            <dt>الرقم المرجعي</dt>
            <dd>
              <bdi dir="ltr" className="admin-ref">
                {summary.reference_number}
              </bdi>
            </dd>
          </div>
          <div>
            <dt>الدورة</dt>
            <dd>{cycleLabel(summary.cycle)}</dd>
          </div>
          <div>
            <dt>المجال الرئيسي</dt>
            <dd>{summary.domain?.name ?? '—'}</dd>
          </div>
          <div>
            <dt>الفئة الفرعية</dt>
            <dd>{summary.subcategory?.name ?? '—'}</dd>
          </div>
          <div>
            <dt>الحالة</dt>
            <dd>
              <StatusBadge status={summary.status} />
            </dd>
          </div>
          <div>
            <dt>وقت الإرسال</dt>
            <dd>{formatDamascusDateTime(summary.submitted_at)} بتوقيت دمشق</dd>
          </div>
          <div>
            <dt>النسخة المرسلة</dt>
            <dd>
              {summary.version_number}
              <span className="admin-hash" title="بصمة SHA-256 لمحتوى النسخة المرسلة">
                <bdi dir="ltr">{summary.content_hash.slice(0, 16)}…</bdi>
              </span>
            </dd>
          </div>
        </dl>
      </Section>

      <Section id="admin-decision" title="قرار اللجنة">
        {user && detail.judging.ready && (
          <div className="admin-section__tools">
            <ExportMenu user={user} label="تصدير تقييمات الحكام" url={(format) => `/api/admin/exports/judge-scores?format=${format}&application=${summary.id}`} />
          </div>
        )}
        {!detail.judging.ready ? (
          <p className="admin-muted">لم تُعتمد لجنة تحكيم هذا المحور بعد.</p>
        ) : (
          <>
            <p className="admin-muted">
              {detail.judging.excluded
                ? 'النتيجة: استبعاد.'
                : detail.judging.decision
                  ? `متوسط التقييمات ${detail.judging.decision} من 100.`
                  : `أُرسل ${detail.judging.received} من ${detail.judging.expected}. يظهر المتوسط عندما يرسل كل أعضاء اللجنة، ما لم يُستبعد المشارك.`}
            </p>
            <dl className="admin-facts">
              {detail.judging.scores.map((item, index) => (
                <div key={`${item.name}-${index}`}>
                  <dt>{item.name}</dt>
                  <dd>
                    {item.excluded ? 'استبعاد' : item.score === null ? 'بانتظار الإرسال' : `${item.score} من 100`}
                    {item.notes ? ` — الملاحظات: ${item.notes}` : ''}
                    {item.recommendations ? ` — التوصيات: ${item.recommendations}` : ''}
                  </dd>
                </div>
              ))}
            </dl>
          </>
        )}
      </Section>

      <Section id="admin-stage-1" title="المرحلة 1: البيانات الأساسية">
        {detail.basic_information ? (
          <ProfileSummary groups={summaryFromProfile(detail.basic_information)} headingLevel={3} />
        ) : (
          <p className="admin-muted">لم يُعثر على البيانات الأساسية المشار إليها في النسخة المرسلة.</p>
        )}
      </Section>

      <Section id="admin-stage-2" title="المرحلة 2: فئة الترشح وبيانات العمل">
        {detail.nomination_details ? (
          <ProfileSummary groups={nominationSummaryFromDetails(detail.nomination_details)} headingLevel={3} />
        ) : (
          <p className="admin-muted">لا توجد بيانات للمرحلة 2 في النسخة المرسلة.</p>
        )}
      </Section>

      <Section id="admin-stage-3" title="المرحلة 3: ملفات العمل وتوثيقه">
        {pendingScan && (
          <p className="admin-scan-note">
            لم تُفحص هذه الملفات بحثاً عن البرمجيات الضارة (الحالة: بانتظار الفحص). تحقّق المنصة من نوعها وبنيتها فقط؛ تعامل معها بوصفها ملفات غير موثوقة.
          </p>
        )}
        {(['explanatory', 'supporting', 'video'] as AttachmentKind[]).map((kind) => (
          <div key={kind} className="summary-group">
            <h3 className="summary-group__title">{SECTION_TITLES[kind]}</h3>
            <AttachmentList
              attachments={documentation.attachments.filter((item) => item.kind === kind)}
              urlFor={(attachment) => adminFileUrl(summary.id, attachment)}
              emptyText="لم يُضف شيء."
            />
          </div>
        ))}
        <div className="summary-group">
          <h3 className="summary-group__title">روابط توثيق إضافية</h3>
          <LinkList links={documentation.links} emptyText="لم تُضف روابط." />
        </div>
      </Section>

      <Section id="admin-declarations" title="الإقرارات والسياسات المقبولة">
        <dl className="admin-facts">
          <div>
            <dt>إقرار صحة البيانات</dt>
            <dd>{declarations.data_accuracy_declared_at ? `${formatDamascusDateTime(declarations.data_accuracy_declared_at)} بتوقيت دمشق` : '—'}</dd>
          </div>
          {declarations.accepted_policies.map((policy) => (
            <div key={`${policy.code}-${policy.version_number}`}>
              <dt>{policy.title ?? POLICY_NAMES[policy.code as PolicyCode] ?? policy.code}</dt>
              <dd>
                الإصدار {policy.version_number}
                {policy.accepted_at && ` · قُبلت في ${formatDamascusDateTime(policy.accepted_at)} بتوقيت دمشق`}
              </dd>
            </div>
          ))}
        </dl>
      </Section>
      {backLink}
    </div>
  )
}
