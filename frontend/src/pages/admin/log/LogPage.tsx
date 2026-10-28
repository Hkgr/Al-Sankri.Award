import { Fragment, useEffect, useId, useRef, useState } from 'react'
import type { FormEvent } from 'react'
import type { User } from '../../../auth/useAuth'
import { FormAlert } from '../../../components/forms/FormControls'
import { api, errorMessage } from '../../../lib/api'
import { useRouter } from '../../../lib/useRouter'
import { usePageTitle } from '../../auth/usePageTitle'
import { formatDamascusDateTime } from '../../candidate/candidateApi'
import { formatBytes } from '../../candidate/documentation/documentationApi'
import type { AdminCycle, AuditPage, SystemLogEntry, SystemLogPage } from '../adminApi'
import { cycleLabel, LOG_LEVELS } from '../adminApi'
import { ExportMenu } from '../ExportMenu'

const AUDIT_PARAMS = ['from', 'to', 'actor', 'action', 'subject_type', 'subject_id', 'cycle', 'request_id', 'page', 'per_page'] as const
const SYSTEM_PARAMS = ['source', 'level', 'from', 'to'] as const

function pick(search: string, names: readonly string[]): string {
  const given = new URLSearchParams(search)
  const query = new URLSearchParams()
  for (const name of names) {
    const value = given.get(name)
    if (value) query.set(name, value)
  }
  return query.toString()
}

/**
 * «السجل»: «سجل الحركات» (audit.view) and «الأخطاء التقنية»
 * (logs.view_system), each shown only to accounts allowed to see it. Both
 * are read-only; filters live in the address.
 */
export function LogPage({ user, cycles }: { user: User; cycles: AdminCycle[] }) {
  usePageTitle('السجل')
  const { location, navigate } = useRouter()
  const canAudit = user.admin_permissions.includes('audit.view')
  const canSystem = user.admin_permissions.includes('logs.view_system')
  const requested = new URLSearchParams(location.search).get('view')
  const view = requested === 'errors' && canSystem ? 'errors' : canAudit ? 'activity' : 'errors'

  const update = (changes: Record<string, string | null>, keepPage = false) => {
    const next = new URLSearchParams(location.search)
    for (const [name, value] of Object.entries(changes)) {
      if (value) next.set(name, value)
      else next.delete(name)
    }
    if (!keepPage && !('page' in changes)) next.delete('page')
    navigate(`/admin/log?${next.toString()}`, { replace: true, keepPosition: true })
  }

  return (
    <div className="admin-page">
      <header className="admin-page__header">
        <h1 className="admin-page__title">السجل</h1>
        <p className="admin-page__intro">للعرض فقط. «سجل الحركات» يعرض ما سُجّل من أحداث المنصة؛ «الأخطاء التقنية» ملخّصات منقّحة من سجلات التطبيق.</p>
      </header>
      <div className="admin-tabs admin-tabs--plain" role="tablist" aria-label="أقسام السجل">
        {canAudit && (
          <button type="button" role="tab" className="admin-tab" aria-selected={view === 'activity'} onClick={() => navigate('/admin/log?view=activity', { replace: true, keepPosition: true })}>
            <span className="admin-tab__name">سجل الحركات</span>
          </button>
        )}
        {canSystem && (
          <button type="button" role="tab" className="admin-tab" aria-selected={view === 'errors'} onClick={() => navigate('/admin/log?view=errors', { replace: true, keepPosition: true })}>
            <span className="admin-tab__name">الأخطاء التقنية</span>
          </button>
        )}
      </div>
      <div role="tabpanel" className="admin-panel">
        {view === 'activity' ? <ActivityLog user={user} cycles={cycles} update={update} /> : <SystemLog user={user} update={update} />}
      </div>
    </div>
  )
}

function ActivityLog({ user, cycles, update }: { user: User; cycles: AdminCycle[]; update: (changes: Record<string, string | null>) => void }) {
  const { location } = useRouter()
  const params = new URLSearchParams(location.search)
  const query = pick(location.search, AUDIT_PARAMS)
  const [result, setResult] = useState<AuditPage | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [actor, setActor] = useState(params.get('actor') ?? '')
  const [requestId, setRequestId] = useState(params.get('request_id') ?? '')
  const [open, setOpen] = useState<number | null>(null)
  const [retry, setRetry] = useState(0)
  const latest = useRef(0)
  const ids = { from: useId(), to: useId(), actor: useId(), action: useId(), subject: useId(), cycle: useId(), request: useId(), perPage: useId() }

  useEffect(() => {
    const request = ++latest.current
    // The request starts here; the rows arrive when it settles.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setLoading(true)
    setError(null)
    api
      .get<AuditPage>(`/api/admin/log/audit?${query}`)
      .then((page) => request === latest.current && setResult(page))
      .catch((failure) => request === latest.current && setError(errorMessage(failure)))
      .finally(() => request === latest.current && setLoading(false))
  }, [query, retry])

  const meta = result?.meta
  const onSearch = (event: FormEvent) => {
    event.preventDefault()
    update({ actor: actor.trim() || null, request_id: requestId.trim() || null })
  }
  const cycle = params.get('cycle') ?? 'all'

  return (
    <>
      <form className="admin-toolbar" onSubmit={onSearch} aria-label="تصفية سجل الحركات">
        <div className="admin-toolbar__filters">
          <div className="admin-filter">
            <label htmlFor={ids.from}>من تاريخ</label>
            <input id={ids.from} type="date" value={params.get('from') ?? ''} onChange={(event) => update({ from: event.target.value || null })} />
          </div>
          <div className="admin-filter">
            <label htmlFor={ids.to}>إلى تاريخ</label>
            <input id={ids.to} type="date" value={params.get('to') ?? ''} onChange={(event) => update({ to: event.target.value || null })} />
          </div>
          <div className="admin-filter">
            <label htmlFor={ids.actor}>المنفّذ</label>
            <input id={ids.actor} type="search" value={actor} onChange={(event) => setActor(event.target.value)} placeholder="الاسم أو البريد، أو system" maxLength={150} />
          </div>
          <div className="admin-filter">
            <label htmlFor={ids.action}>الحركة</label>
            <select id={ids.action} value={params.get('action') ?? ''} onChange={(event) => update({ action: event.target.value || null })}>
              <option value="">كل الحركات</option>
              {Object.entries(result?.actions ?? {}).map(([code, label]) => (
                <option key={code} value={code}>
                  {label}
                </option>
              ))}
            </select>
          </div>
          <div className="admin-filter">
            <label htmlFor={ids.subject}>السجل المتأثر</label>
            <select id={ids.subject} value={params.get('subject_type') ?? ''} onChange={(event) => update({ subject_type: event.target.value || null, subject_id: null })}>
              <option value="">كل الأنواع</option>
              {Object.entries(result?.subjects ?? {}).map(([code, label]) => (
                <option key={code} value={code}>
                  {label}
                </option>
              ))}
            </select>
          </div>
          <div className="admin-filter">
            <label htmlFor={ids.cycle}>الدورة</label>
            <select id={ids.cycle} value={cycle} onChange={(event) => update({ cycle: event.target.value === 'all' ? null : event.target.value })}>
              <option value="all">كل الدورات، مع الأحداث العامة</option>
              <option value="none">أحداث بلا دورة</option>
              {cycles.map((item) => (
                <option key={item.id} value={item.id}>
                  {cycleLabel(item)}
                </option>
              ))}
            </select>
          </div>
          <div className="admin-filter">
            <label htmlFor={ids.request}>معرّف الطلب</label>
            <input id={ids.request} type="search" dir="ltr" value={requestId} onChange={(event) => setRequestId(event.target.value)} maxLength={64} />
          </div>
          <button type="submit" className="button button--primary button--small">
            تطبيق
          </button>
        </div>
        <p className="admin-muted admin-toolbar__note">
          تصفية الدورة تعتمد على الدورة المسجّلة مع الحدث فقط. الأحداث القديمة أو العامة التي لم تُسجَّل معها دورة لا تُنسب إلى أي دورة.
        </p>
      </form>

      <div className="admin-results-bar">
        <p className="admin-results" role="status" aria-live="polite">
          {loading ? 'جارٍ التحميل…' : meta ? (meta.total === 0 ? 'لا أحداث تطابق هذه المرشّحات.' : `${meta.total} حدث، الأحدث أولاً`) : ''}
        </p>
        <ExportMenu user={user} url={(format) => `/api/admin/exports/audit?format=${format}&${query}`} />
      </div>

      {error ? (
        <FormAlert tone="error">
          <p>تعذّر تحميل سجل الحركات. {error}</p>
          <p>
            <button type="button" className="button button--secondary button--small" onClick={() => setRetry((count) => count + 1)}>
              إعادة المحاولة
            </button>
          </p>
        </FormAlert>
      ) : result && result.data.length > 0 ? (
        <div className="admin-table-wrap" tabIndex={0} role="region" aria-label="سجل الحركات" aria-busy={loading || undefined}>
          <table className="admin-table">
            <thead>
              <tr>
                <th scope="col">الوقت (دمشق)</th>
                <th scope="col">المنفّذ</th>
                <th scope="col">الحركة</th>
                <th scope="col">السجل المتأثر</th>
                <th scope="col">الدورة</th>
                <th scope="col">التفاصيل</th>
              </tr>
            </thead>
            <tbody>
              {result.data.map((entry) => (
                <Fragment key={entry.id}>
                  <tr>
                    <td className="admin-table__time">{formatDamascusDateTime(entry.occurred_at)}</td>
                    <td>{entry.actor ? <bdi>{entry.actor.name}</bdi> : <span className="admin-muted">النظام</span>}</td>
                    <td>{entry.action_label}</td>
                    <td>{entry.subject ? <bdi>{`${entry.subject.type_label} ${entry.subject.reference ?? `#${entry.subject.id}`}`}</bdi> : '—'}</td>
                    <td>{entry.cycle ? cycleLabel(entry.cycle) : <span className="admin-muted">بلا دورة</span>}</td>
                    <td>
                      <button
                        type="button"
                        className="button button--quiet button--small"
                        aria-expanded={open === entry.id}
                        onClick={() => setOpen((current) => (current === entry.id ? null : entry.id))}
                      >
                        {open === entry.id ? 'إخفاء' : 'عرض'}
                        <span className="visually-hidden"> تفاصيل {entry.action_label}</span>
                      </button>
                    </td>
                  </tr>
                  {open === entry.id && (
                    <tr className="admin-table__details">
                      <td colSpan={6}>
                        <dl className="admin-facts admin-facts--compact">
                          <div>
                            <dt>المنفّذ</dt>
                            <dd>{entry.actor ? <bdi>{`${entry.actor.name} (${entry.actor.email})`}</bdi> : 'النظام أو حدث بلا حساب'}</dd>
                          </div>
                          <div>
                            <dt>رمز الحركة</dt>
                            <dd>
                              <bdi dir="ltr">{entry.action}</bdi>
                            </dd>
                          </div>
                          {entry.request_id && (
                            <div>
                              <dt>معرّف الطلب</dt>
                              <dd>
                                <button type="button" className="admin-link-button" onClick={() => update({ request_id: entry.request_id })}>
                                  <bdi dir="ltr">{entry.request_id}</bdi>
                                </button>
                              </dd>
                            </div>
                          )}
                          {entry.details.map((detail) => (
                            <div key={`${detail.label}-${detail.key}`}>
                              <dt>{detail.label}</dt>
                              <dd>
                                <bdi>{detail.value}</bdi>
                              </dd>
                            </div>
                          ))}
                        </dl>
                      </td>
                    </tr>
                  )}
                </Fragment>
              ))}
            </tbody>
          </table>
        </div>
      ) : result && !loading ? (
        <div className="admin-empty">
          <p className="admin-empty__title">لا أحداث تطابق هذه المرشّحات.</p>
        </div>
      ) : null}

      {meta && meta.total > 0 && (
        <nav className="admin-pager" aria-label="صفحات السجل">
          <button type="button" className="button button--secondary button--small" disabled={meta.page <= 1 || loading} onClick={() => update({ page: String(meta.page - 1) })}>
            الأحدث
          </button>
          <span className="admin-pager__status">
            الصفحة {meta.page} من {meta.last_page}
          </span>
          <button type="button" className="button button--secondary button--small" disabled={meta.page >= meta.last_page || loading} onClick={() => update({ page: String(meta.page + 1) })}>
            الأقدم
          </button>
          <span className="admin-filter admin-filter--inline">
            <label htmlFor={ids.perPage}>لكل صفحة</label>
            <select id={ids.perPage} value={String(meta.per_page)} onChange={(event) => update({ per_page: event.target.value })}>
              {[25, 50, 100].map((size) => (
                <option key={size} value={size}>
                  {size}
                </option>
              ))}
            </select>
          </span>
        </nav>
      )}
    </>
  )
}

function SystemLog({ user, update }: { user: User; update: (changes: Record<string, string | null>) => void }) {
  const { location } = useRouter()
  const params = new URLSearchParams(location.search)
  const query = pick(location.search, SYSTEM_PARAMS)
  const [result, setResult] = useState<SystemLogPage | null>(null)
  const [entries, setEntries] = useState<SystemLogEntry[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [retry, setRetry] = useState(0)
  const latest = useRef(0)
  const ids = { source: useId(), level: useId(), from: useId(), to: useId() }

  const load = (cursor: number | null) => {
    const request = ++latest.current
    setLoading(true)
    setError(null)
    api
      .get<SystemLogPage>(`/api/admin/log/system?${query}${cursor === null ? '' : `&cursor=${cursor}`}`)
      .then((page) => {
        if (request !== latest.current) return
        setResult(page)
        setEntries((current) => (cursor === null ? (page.data?.entries ?? []) : [...current, ...(page.data?.entries ?? [])]))
      })
      .catch((failure) => request === latest.current && setError(errorMessage(failure)))
      .finally(() => request === latest.current && setLoading(false))
  }

  useEffect(() => {
    // A new source or filter starts again from the newest entries.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load(null)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [query, retry])

  const page = result?.data ?? null
  const source = page?.source.id ?? params.get('source') ?? ''

  return (
    <>
      <div className="admin-toolbar" aria-label="تصفية الأخطاء التقنية">
        <div className="admin-toolbar__filters">
          <div className="admin-filter">
            <label htmlFor={ids.source}>ملف السجل</label>
            <select id={ids.source} value={source} onChange={(event) => update({ source: event.target.value || null })} disabled={!result?.sources.length}>
              {(result?.sources ?? []).map((item) => (
                <option key={item.id} value={item.id}>
                  {item.id} · {formatBytes(item.size_bytes)}
                </option>
              ))}
            </select>
          </div>
          <div className="admin-filter">
            <label htmlFor={ids.level}>المستوى</label>
            <select id={ids.level} value={params.get('level') ?? ''} onChange={(event) => update({ level: event.target.value || null })}>
              <option value="">كل المستويات</option>
              {LOG_LEVELS.map((level) => (
                <option key={level} value={level}>
                  {level}
                </option>
              ))}
            </select>
          </div>
          <div className="admin-filter">
            <label htmlFor={ids.from}>من تاريخ</label>
            <input id={ids.from} type="date" value={params.get('from') ?? ''} onChange={(event) => update({ from: event.target.value || null })} />
          </div>
          <div className="admin-filter">
            <label htmlFor={ids.to}>إلى تاريخ</label>
            <input id={ids.to} type="date" value={params.get('to') ?? ''} onChange={(event) => update({ to: event.target.value || null })} />
          </div>
        </div>
      </div>

      {result && (
        <div className="admin-coverage">
          <p>
            <strong>التغطية:</strong>{' '}
            {result.sources.length === 0
              ? 'لا توجد ملفات سجل قابلة للقراءة.'
              : `${result.sources.length} ملف سجل متاح${result.coverage.retention_days ? `؛ تحتفظ إعدادات الخادم بسجلات آخر ${result.coverage.retention_days} يوماً تقريباً` : ''}. ما حُذف أو دُوّر خارج هذه الملفات غير متاح.`}
          </p>
          {page && (
            <p>
              <strong>النافذة المقروءة:</strong> {formatBytes(page.window.scanned_bytes)} من {formatBytes(page.source.size_bytes)}
              {page.window.newest && ` · من ${formatDamascusDateTime(page.window.newest)}`}
              {page.window.oldest && ` إلى ${formatDamascusDateTime(page.window.oldest)}`}
              {page.window.reached_start ? ' · وصلت القراءة إلى بداية الملف.' : ''}
            </p>
          )}
          <p className="admin-muted">
            تُعرض حقول مختارة فقط (الوقت والمستوى ونوع الاستثناء والموضع ومعرّف الطلب). نصوص الرسائل وتتبّع الشيفرة وبيانات الطلبات لا تُعرض. هذه إدخالات عامة لا تخص دورة،
            فلا تصفّيها الدورة المختارة في رأس الصفحة.
          </p>
        </div>
      )}

      <div className="admin-results-bar">
        <p className="admin-results" role="status" aria-live="polite">
          {loading ? 'جارٍ القراءة…' : result?.status === 'unavailable' ? 'السجل غير متاح.' : `${entries.length} إدخال معروض`}
        </p>
        {result?.status === 'available' && (
          <ExportMenu user={user} url={(format) => `/api/admin/exports/system-logs?format=${format}&${query || `source=${encodeURIComponent(source)}`}`} />
        )}
      </div>

      {error ? (
        <FormAlert tone="error">
          <p>تعذّر قراءة السجل. {error}</p>
          <p>
            <button type="button" className="button button--secondary button--small" onClick={() => setRetry((count) => count + 1)}>
              إعادة المحاولة
            </button>
          </p>
        </FormAlert>
      ) : result?.status === 'unavailable' ? (
        <FormAlert tone="info">
          <p>لا توجد ملفات سجل للتطبيق يمكن قراءتها على الخادم الآن (قد تكون غير منشأة بعد أو غير قابلة للقراءة).</p>
        </FormAlert>
      ) : entries.length > 0 ? (
        <div className="admin-table-wrap" tabIndex={0} role="region" aria-label="الأخطاء التقنية" aria-busy={loading || undefined}>
          <table className="admin-table">
            <thead>
              <tr>
                <th scope="col">الوقت (دمشق)</th>
                <th scope="col">المستوى</th>
                <th scope="col">الملخص</th>
                <th scope="col">الفئة</th>
                <th scope="col">الموضع</th>
                <th scope="col">معرّف الطلب</th>
              </tr>
            </thead>
            <tbody>
              {entries.map((entry) => (
                <tr key={entry.offset}>
                  <td className="admin-table__time">{entry.time ? formatDamascusDateTime(entry.time) : '—'}</td>
                  <td>
                    <span className="admin-level" data-level={entry.level}>
                      {entry.level}
                    </span>
                  </td>
                  <td>{entry.summary}</td>
                  <td>
                    <bdi dir="ltr" className="admin-ref">
                      {entry.category}
                    </bdi>
                  </td>
                  <td>{entry.location ? <bdi dir="ltr">{entry.location}</bdi> : '—'}</td>
                  <td>{entry.request_id ? <bdi dir="ltr" className="admin-ref">{entry.request_id}</bdi> : '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : result && !loading ? (
        <div className="admin-empty">
          <p className="admin-empty__title">لا إدخالات تطابق هذه المرشّحات في النافذة المقروءة.</p>
        </div>
      ) : null}

      {page?.next_cursor !== null && page?.next_cursor !== undefined && (
        <p className="admin-pager">
          <button type="button" className="button button--secondary button--small" disabled={loading} onClick={() => load(page.next_cursor)}>
            {loading ? 'جارٍ القراءة…' : 'قراءة إدخالات أقدم'}
          </button>
        </p>
      )}
    </>
  )
}
