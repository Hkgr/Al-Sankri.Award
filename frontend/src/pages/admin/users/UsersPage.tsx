import { useEffect, useId, useRef, useState } from 'react'
import type { FormEvent } from 'react'
import type { User } from '../../../auth/useAuth'
import { FormAlert } from '../../../components/forms/FormControls'
import { api, errorMessage } from '../../../lib/api'
import { Link } from '../../../lib/router'
import { useRouter } from '../../../lib/useRouter'
import { usePageTitle } from '../../auth/usePageTitle'
import { formatDamascusDateTime } from '../../candidate/candidateApi'
import type { AdminCycle, StageState, UserProgressState, UserRow, UserSortKey, UsersPage as Page } from '../adminApi'
import { cycleLabel, PROGRESS_LABELS, USER_ROLE_FILTERS, USER_SORT_LABELS } from '../adminApi'
import { ReviewBadge } from '../applications/ReviewBadge'
import { ExportMenu } from '../ExportMenu'
import { usersQuery } from './usersState'

const STAGE_LABELS: Record<StageState, string> = { complete: 'مكتملة', incomplete: 'غير مكتملة', none: 'لم تُحفظ بعد' }

function Stages({ progress }: { progress: UserRow['progress'] }) {
  return (
    <ol className="admin-stages" aria-label="المراحل">
      {([progress.stage1, progress.stage2, progress.stage3] as StageState[]).map((stage, index) => (
        <li key={index} data-state={stage} title={`المرحلة ${index + 1}: ${STAGE_LABELS[stage]}`}>
          <span aria-hidden="true">{index + 1}</span>
          <span className="visually-hidden">
            المرحلة {index + 1}: {STAGE_LABELS[stage]}
          </span>
        </li>
      ))}
    </ol>
  )
}

function Roles({ roles }: { roles: UserRow['roles'] }) {
  if (roles.platform.length === 0 && roles.cycle.length === 0) return <span className="admin-muted">لا أدوار حالية</span>
  return (
    <ul className="admin-roles">
      {roles.platform.map((role) => (
        <li key={role.code} className="admin-role" data-role={role.code}>
          {role.name}
        </li>
      ))}
      {roles.cycle.map((role) => (
        <li key={`${role.code}-${role.cycle.id}`} className="admin-role admin-role--cycle" data-role={role.code}>
          {role.name}
          <span className="admin-role__scope">
            {cycleLabel(role.cycle)}
            {role.domain && ` · ${role.domain}`}
          </span>
        </li>
      ))}
    </ul>
  )
}

/**
 * «المستخدمون»: every registered account, read-only, with its current roles
 * and its candidate progress in the cycle selected in the header (scope
 * from AdminApp). Accounts without a candidature in that cycle stay listed.
 * Filters, sorting and page live in the address; the request uses the same
 * values.
 */
export function UsersPage({ user, cycles, scope }: { user: User; cycles: AdminCycle[]; scope: number | null }) {
  usePageTitle('المستخدمون')
  const { location, navigate } = useRouter()
  const params = new URLSearchParams(location.search)
  const sort = (params.get('sort') ?? 'registered_at') as UserSortKey
  const direction = params.get('direction') === 'asc' ? 'asc' : 'desc'
  const [result, setResult] = useState<Page | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const q = params.get('q') ?? ''
  const [search, setSearch] = useState(q)
  const [shownQ, setShownQ] = useState(q)
  if (q !== shownQ) {
    // Back/forward restored another search: the box follows the address.
    setShownQ(q)
    setSearch(q)
  }
  const [retry, setRetry] = useState(0)
  const latest = useRef(0)
  const ids = { search: useId(), role: useId(), state: useId(), verified: useId(), progress: useId(), perPage: useId(), caption: useId() }
  const queryString = usersQuery(location.search, scope)
  const canOpenApplications = user.admin_permissions.includes('applications.view_submitted')
  const cycle = cycles.find((item) => item.id === scope) ?? null

  useEffect(() => {
    const request = ++latest.current
    // The request starts here; the rows arrive when it settles.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setLoading(true)
    setError(null)
    api
      .get<Page>(`/api/admin/users?${queryString}`)
      .then((page) => request === latest.current && setResult(page))
      .catch((failure) => request === latest.current && setError(errorMessage(failure)))
      .finally(() => request === latest.current && setLoading(false))
  }, [queryString, retry])

  /** New filter values; the page goes back to 1 unless the page itself changes. */
  const update = (changes: Record<string, string | null>) => {
    const next = new URLSearchParams(location.search)
    if (scope !== null) next.set('cycle', String(scope))
    for (const [name, value] of Object.entries(changes)) {
      if (value) next.set(name, value)
      else next.delete(name)
    }
    if (!('page' in changes)) next.delete('page')
    navigate(`/admin/users?${next.toString()}`, { replace: true, keepPosition: true })
  }

  const onSearch = (event: FormEvent) => {
    event.preventDefault()
    update({ q: search.trim() || null })
  }

  /** The same column flips its direction; dates start newest-first, names A–Z. */
  const onSort = (key: UserSortKey) => {
    update({ sort: key, direction: sort === key ? (direction === 'asc' ? 'desc' : 'asc') : key === 'name' ? 'asc' : 'desc' })
  }

  const meta = result?.meta
  const filtered = ['q', 'role', 'state', 'verified', 'progress'].some((name) => params.get(name))
  // Without a cycle only Stage 1 can be known.
  const progressOptions: UserProgressState[] =
    scope === null
      ? ['no_basic_information', 'basic_information_only', 'not_required']
      : (Object.keys(PROGRESS_LABELS) as UserProgressState[]).filter((state) => state !== 'basic_information_only')

  const sortHeader = (key: UserSortKey, label: string) => (
    <th scope="col" aria-sort={sort === key ? (direction === 'asc' ? 'ascending' : 'descending') : undefined}>
      <button type="button" className="admin-table__sort" onClick={() => onSort(key)}>
        {label}
        <span className="admin-table__sort-icon" aria-hidden="true">
          {sort === key ? (direction === 'asc' ? '▲' : '▼') : '↕'}
        </span>
      </button>
    </th>
  )

  return (
    <div className="admin-page">
      <header className="admin-page__header">
        <h1 className="admin-page__title">المستخدمون</h1>
        <p className="admin-page__intro">
          كل الحسابات المسجّلة، للعرض فقط: الأدوار الحالية، وحالة الحساب، وتقدّم الترشح في {cycle ? <strong>{cycleLabel(cycle)}</strong> : 'الدورة المختارة'}.
          يُحسب التقدّم مما حُفظ فعلاً؛ ما يكتبه المترشح ولم يحفظه لا يُعرف.
        </p>
      </header>

      {scope === null ? (
        <FormAlert tone="info">
          <p>لا توجد دورة جائزة بعد، فتقدّم المرحلتين 2 و3 غير متاح. تُعرض الحسابات وحالة البيانات الأساسية فقط.</p>
        </FormAlert>
      ) : (
        meta?.cycle &&
        !meta.cycle.stage_open && (
          <FormAlert tone="info">
            <p>باب الترشح في هذه الدورة غير مفتوح الآن. تُعرض المراحل المكتملة كما حُفظت، ولا يستطيع المترشحون تغييرها حالياً.</p>
          </FormAlert>
        )
      )}

      <div className="admin-toolbar" role="search" aria-label="البحث في الحسابات وتصفيتها">
        <form className="admin-toolbar__search" onSubmit={onSearch}>
          <label htmlFor={ids.search}>بحث</label>
          <div className="admin-toolbar__search-row">
            <input id={ids.search} type="search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="الاسم أو البريد الإلكتروني" maxLength={150} />
            <button type="submit" className="button button--primary button--small">
              بحث
            </button>
          </div>
        </form>
        <div className="admin-toolbar__filters">
          <div className="admin-filter">
            <label htmlFor={ids.role}>الدور</label>
            <select id={ids.role} value={params.get('role') ?? ''} onChange={(event) => update({ role: event.target.value || null })}>
              <option value="">كل الأدوار</option>
              {Object.entries(USER_ROLE_FILTERS).map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </select>
          </div>
          <div className="admin-filter">
            <label htmlFor={ids.state}>حالة الحساب</label>
            <select id={ids.state} value={params.get('state') ?? ''} onChange={(event) => update({ state: event.target.value || null })}>
              <option value="">الكل</option>
              <option value="active">نشط</option>
              <option value="disabled">معطّل</option>
            </select>
          </div>
          <div className="admin-filter">
            <label htmlFor={ids.verified}>تأكيد البريد</label>
            <select id={ids.verified} value={params.get('verified') ?? ''} onChange={(event) => update({ verified: event.target.value || null })}>
              <option value="">الكل</option>
              <option value="verified">مؤكَّد</option>
              <option value="unverified">غير مؤكَّد</option>
            </select>
          </div>
          <div className="admin-filter">
            <label htmlFor={ids.progress}>تقدّم الترشح</label>
            <select id={ids.progress} value={params.get('progress') ?? ''} onChange={(event) => update({ progress: event.target.value || null })}>
              <option value="">كل الحالات</option>
              {progressOptions.map((state) => (
                <option key={state} value={state}>
                  {PROGRESS_LABELS[state]}
                </option>
              ))}
            </select>
          </div>
          {filtered && (
            <button
              type="button"
              className="button button--quiet button--small admin-toolbar__reset"
              onClick={() => {
                setSearch('')
                update({ q: null, role: null, state: null, verified: null, progress: null })
              }}
            >
              مسح المرشّحات
            </button>
          )}
        </div>
      </div>

      <div className="admin-results-bar">
        <p className="admin-results" role="status" aria-live="polite">
          {loading ? 'جارٍ التحميل…' : meta ? `${meta.total === 0 ? 'لا نتائج' : `${meta.total} حساب`} · التقدّم في: ${meta.cycle ? cycleLabel(meta.cycle) : 'لا دورة'}` : ''}
        </p>
        <ExportMenu user={user} url={(format) => `/api/admin/exports/users?format=${format}&${queryString}`} />
      </div>

      {error ? (
        <FormAlert tone="error">
          <p>تعذّر تحميل الحسابات. {error}</p>
          <p>
            <button type="button" className="button button--secondary button--small" onClick={() => setRetry((count) => count + 1)}>
              إعادة المحاولة
            </button>
          </p>
        </FormAlert>
      ) : result && result.data.length === 0 && !loading ? (
        <div className="admin-empty">
          <p className="admin-empty__title">{filtered ? 'لا توجد حسابات تطابق هذا البحث أو هذه المرشّحات.' : 'لا توجد حسابات مسجّلة بعد.'}</p>
          {filtered && <p>جرّب تغيير المرشّحات أو مسحها.</p>}
        </div>
      ) : result ? (
        <div className="admin-table-wrap" tabIndex={0} role="region" aria-labelledby={ids.caption} aria-busy={loading || undefined}>
          <table className="admin-table">
            <caption id={ids.caption} className="visually-hidden">
              الحسابات المسجّلة، الصفحة {meta?.page} من {meta?.last_page}، مرتّبة حسب {USER_SORT_LABELS[sort]}
            </caption>
            <thead>
              <tr>
                {sortHeader('name', 'الاسم')}
                <th scope="col">البريد الإلكتروني</th>
                <th scope="col">الأدوار الحالية</th>
                <th scope="col">الحساب</th>
                {sortHeader('registered_at', 'تاريخ التسجيل')}
                {sortHeader('last_login_at', 'آخر تسجيل دخول')}
                <th scope="col">تقدّم الترشح</th>
                <th scope="col">الإرسال النهائي</th>
              </tr>
            </thead>
            <tbody>
              {result.data.map((row) => (
                <tr key={row.id}>
                  <td>
                    <bdi>{row.name}</bdi>
                  </td>
                  <td>
                    <bdi dir="ltr">{row.email}</bdi>
                    <span className="admin-verified" data-verified={row.email_verified}>
                      {row.email_verified ? 'مؤكَّد' : 'غير مؤكَّد'}
                    </span>
                  </td>
                  <td>
                    <Roles roles={row.roles} />
                  </td>
                  <td>
                    <span className="admin-state" data-state={row.active ? 'active' : 'disabled'}>
                      {row.active ? 'نشط' : 'معطّل'}
                    </span>
                  </td>
                  <td className="admin-table__time">{row.registered_at ? formatDamascusDateTime(row.registered_at) : '—'}</td>
                  <td className="admin-table__time">{row.last_login_at ? formatDamascusDateTime(row.last_login_at) : 'لم يسجّل دخولاً'}</td>
                  <td className="admin-progress">
                    <span className="admin-progress__label" data-state={row.progress.state}>
                      {PROGRESS_LABELS[row.progress.state]}
                    </span>
                    {row.progress.state !== 'not_required' && <Stages progress={row.progress} />}
                  </td>
                  <td className="admin-table__time">
                    {row.submission ? (
                      <>
                        <span className="admin-submitted">
                          أُرسل · <bdi dir="ltr">{row.submission.reference_number}</bdi>
                        </span>
                        {row.submission.submitted_at && <span className="admin-submission-time">{formatDamascusDateTime(row.submission.submitted_at)}</span>}
                        <ReviewBadge status={row.submission.review} />
                        {canOpenApplications && row.submission.application_id !== null && (
                          <Link className="admin-row-link" href={`/admin/applications/${row.submission.application_id}`}>
                            عرض الملف
                            <span className="visually-hidden"> {row.submission.reference_number}</span>
                          </Link>
                        )}
                      </>
                    ) : row.progress.state === 'not_required' ? (
                      '—'
                    ) : (
                      'لم يُرسل'
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}

      {meta && meta.total > 0 && (
        <nav className="admin-pager" aria-label="صفحات الجدول">
          <button type="button" className="button button--secondary button--small" disabled={meta.page <= 1 || loading} onClick={() => update({ page: String(meta.page - 1) })}>
            السابق
          </button>
          <span className="admin-pager__status">
            الصفحة {meta.page} من {meta.last_page}
          </span>
          <button
            type="button"
            className="button button--secondary button--small"
            disabled={meta.page >= meta.last_page || loading}
            onClick={() => update({ page: String(meta.page + 1) })}
          >
            التالي
          </button>
          <span className="admin-filter admin-filter--inline">
            <label htmlFor={ids.perPage}>لكل صفحة</label>
            <select id={ids.perPage} value={String(meta.per_page)} onChange={(event) => update({ per_page: event.target.value })}>
              {[10, 25, 50].map((size) => (
                <option key={size} value={size}>
                  {size}
                </option>
              ))}
            </select>
          </span>
        </nav>
      )}
    </div>
  )
}
