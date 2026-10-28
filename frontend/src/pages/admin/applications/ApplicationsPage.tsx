import { useEffect, useId, useRef, useState } from 'react'
import type { FormEvent } from 'react'
import { FormAlert } from '../../../components/forms/FormControls'
import { api, errorMessage } from '../../../lib/api'
import { Link } from '../../../lib/router'
import { useRouter } from '../../../lib/useRouter'
import { usePageTitle } from '../../auth/usePageTitle'
import { formatDamascusDateTime } from '../../candidate/candidateApi'
import type { AdminCycle, ApplicationsPage as Page, ApplicationStatus, SortKey } from '../adminApi'
import { APPLICANT_TYPE_LABELS, cycleLabel, SORT_LABELS, STATUS_LABELS } from '../adminApi'
import { StatusBadge } from './StatusBadge'
import { ALL_CYCLES, tableQuery } from './tableState'

/**
 * «ملفات الترشح»: the submitted participations, searched, filtered, sorted
 * and paged on the server. The table's state lives in the address, so a
 * refresh, the back button or «العودة إلى الجدول» restores it. Its cycle
 * scope comes from AdminApp (tableScope), and changing it here or in the
 * header goes through the same onScopeChange.
 */
export function ApplicationsPage({ cycles, scope, onScopeChange }: { cycles: AdminCycle[]; scope: string; onScopeChange: (scope: string) => void }) {
  usePageTitle('ملفات الترشح')
  const { location, navigate } = useRouter()
  const params = new URLSearchParams(location.search)
  const cycleParam = scope
  const cycle = cycles.find((item) => String(item.id) === cycleParam) ?? null
  const domain = params.get('domain') ?? ''
  const sort = (params.get('sort') ?? 'submitted_at') as SortKey
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
  const ids = { search: useId(), cycle: useId(), domain: useId(), subcategory: useId(), type: useId(), status: useId(), perPage: useId(), caption: useId() }

  const queryString = tableQuery(location.search, scope)

  useEffect(() => {
    const request = ++latest.current
    // The request starts here; the rows arrive when it settles.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setLoading(true)
    setError(null)
    api
      .get<Page>(`/api/admin/applications?${queryString}`)
      .then((page) => request === latest.current && setResult(page))
      .catch((failure) => request === latest.current && setError(errorMessage(failure)))
      .finally(() => request === latest.current && setLoading(false))
  }, [queryString, retry])

  /** New filter values; the page goes back to 1 unless the page itself changes. */
  const update = (changes: Record<string, string | null>) => {
    const next = new URLSearchParams(location.search)
    if (!next.has('cycle')) next.set('cycle', cycleParam)
    for (const [name, value] of Object.entries(changes)) {
      if (value) next.set(name, value)
      else next.delete(name)
    }
    if (!('page' in changes)) next.delete('page')
    navigate(`/admin/applications?${next.toString()}`, { replace: true, keepPosition: true })
  }

  const onSearch = (event: FormEvent) => {
    event.preventDefault()
    update({ q: search.trim() || null })
  }


  /** The same column flips its direction; a new one starts newest-first for dates, A–Z otherwise. */
  const onSort = (key: SortKey) => {
    const nextDirection = sort === key ? (direction === 'asc' ? 'desc' : 'asc') : key === 'submitted_at' ? 'desc' : 'asc'
    update({ sort: key, direction: nextDirection })
  }

  // «كل الدورات»: the main domains and subcategories of every cycle, once each.
  const domains =
    cycle?.domains ??
    Object.values(
      Object.fromEntries(
        cycles
          .flatMap((item) => item.domains)
          .reverse()
          .map((item) => [item.code, item]),
      ),
    )
  const subcategories = domains.find((item) => item.code === domain)?.subcategories ?? []
  const filtered = ['domain', 'subcategory', 'applicant_type', 'status', 'q'].some((name) => params.get(name))
  const meta = result?.meta
  const back = new URLSearchParams(queryString)
  back.set('cycle', scope)
  const backTo = `/admin/applications?${back.toString()}`

  const sortHeader = (key: SortKey, label: string) => (
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
        <h1 className="admin-page__title">ملفات الترشح</h1>
        <p className="admin-page__intro">المشاركات المرسلة فقط، بما فيها ما انتقل بعد الإرسال إلى مراحل أخرى. المسودات لا تظهر هنا.</p>
      </header>

      <div className="admin-toolbar" role="search" aria-label="البحث في ملفات الترشح وتصفيتها">
        <form className="admin-toolbar__search" onSubmit={onSearch}>
          <label htmlFor={ids.search}>بحث</label>
          <div className="admin-toolbar__search-row">
            <input
              id={ids.search}
              type="search"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              placeholder="الرقم المرجعي أو عنوان العمل أو اسم المترشح"
              maxLength={150}
            />
            <button type="submit" className="button button--primary button--small">
              بحث
            </button>
          </div>
        </form>
        <div className="admin-toolbar__filters">
          <div className="admin-filter">
            <label htmlFor={ids.cycle}>الدورة</label>
            <select id={ids.cycle} value={scope} onChange={(event) => onScopeChange(event.target.value)}>
              <option value={ALL_CYCLES}>كل الدورات</option>
              {cycles.map((item) => (
                <option key={item.id} value={item.id}>
                  {cycleLabel(item)}
                </option>
              ))}
            </select>
          </div>
          <div className="admin-filter">
            <label htmlFor={ids.domain}>المجال الرئيسي</label>
            <select id={ids.domain} value={domain} onChange={(event) => update({ domain: event.target.value || null, subcategory: null })}>
              <option value="">كل المجالات</option>
              {domains.map((item) => (
                <option key={item.code} value={item.code}>
                  {item.name}
                </option>
              ))}
            </select>
          </div>
          <div className="admin-filter">
            <label htmlFor={ids.subcategory}>الفئة الفرعية</label>
            <select
              id={ids.subcategory}
              value={params.get('subcategory') ?? ''}
              onChange={(event) => update({ subcategory: event.target.value || null })}
              disabled={!domain}
              aria-describedby={domain ? undefined : `${ids.subcategory}-hint`}
            >
              <option value="">{domain ? 'كل الفئات' : 'اختر المجال أولاً'}</option>
              {subcategories.map((item) => (
                <option key={item.code} value={item.code}>
                  {item.name}
                </option>
              ))}
            </select>
            {!domain && (
              <span id={`${ids.subcategory}-hint`} className="visually-hidden">
                تُتاح بعد اختيار المجال الرئيسي.
              </span>
            )}
          </div>
          <div className="admin-filter">
            <label htmlFor={ids.type}>نوع المترشح</label>
            <select id={ids.type} value={params.get('applicant_type') ?? ''} onChange={(event) => update({ applicant_type: event.target.value || null })}>
              <option value="">الكل</option>
              {Object.entries(APPLICANT_TYPE_LABELS).map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </select>
          </div>
          <div className="admin-filter">
            <label htmlFor={ids.status}>الحالة</label>
            <select id={ids.status} value={params.get('status') ?? ''} onChange={(event) => update({ status: event.target.value || null })}>
              <option value="">كل الحالات</option>
              {Object.entries(STATUS_LABELS).map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
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
                update({ domain: null, subcategory: null, applicant_type: null, status: null, q: null })
              }}
            >
              مسح المرشّحات
            </button>
          )}
        </div>
      </div>

      <p className="admin-results" role="status" aria-live="polite">
        {loading ? 'جارٍ التحميل…' : meta ? `${meta.total === 0 ? 'لا نتائج' : `${meta.total} مشاركة مرسلة`} · النطاق: ${cycle ? cycleLabel(cycle) : 'كل الدورات'}` : ''}
      </p>

      {error ? (
        <FormAlert tone="error">
          <p>تعذّر تحميل ملفات الترشح. {error}</p>
          <p>
            <button type="button" className="button button--secondary button--small" onClick={() => setRetry((count) => count + 1)}>
              إعادة المحاولة
            </button>
          </p>
        </FormAlert>
      ) : result && result.data.length === 0 && !loading ? (
        <div className="admin-empty">
          <p className="admin-empty__title">{filtered ? 'لا توجد مشاركات مرسلة تطابق هذا البحث أو هذه المرشّحات.' : 'لم تُرسل أي مشاركة بعد في هذا النطاق.'}</p>
          {filtered && <p>جرّب تغيير المرشّحات أو مسحها.</p>}
        </div>
      ) : result ? (
        <div className="admin-table-wrap" tabIndex={0} role="region" aria-labelledby={ids.caption} aria-busy={loading || undefined}>
          <table className="admin-table">
            <caption id={ids.caption} className="visually-hidden">
              ملفات الترشح المرسلة، الصفحة {meta?.page} من {meta?.last_page}، مرتّبة حسب {SORT_LABELS[sort]}
            </caption>
            <thead>
              <tr>
                {sortHeader('reference', 'الرقم المرجعي')}
                {sortHeader('title', 'عنوان العمل')}
                {sortHeader('applicant', 'المترشح أو المؤسسة')}
                <th scope="col">النوع</th>
                <th scope="col">المجال الرئيسي</th>
                <th scope="col">الفئة الفرعية</th>
                {sortHeader('submitted_at', 'وقت الإرسال')}
                {sortHeader('status', 'الحالة')}
                <th scope="col">المرفقات</th>
                <th scope="col">
                  <span className="visually-hidden">الإجراء</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {result.data.map((row) => (
                <tr key={row.id}>
                  <td>
                    <bdi dir="ltr" className="admin-ref">
                      {row.reference_number}
                    </bdi>
                  </td>
                  <td className="admin-table__title">
                    <bdi>{row.title}</bdi>
                  </td>
                  <td>
                    <bdi>{row.applicant_name}</bdi>
                  </td>
                  <td>{APPLICANT_TYPE_LABELS[row.applicant_type]}</td>
                  <td>{row.domain.name}</td>
                  <td>{row.subcategory?.name ?? '—'}</td>
                  <td className="admin-table__time">{formatDamascusDateTime(row.submitted_at)}</td>
                  <td>
                    <StatusBadge status={row.status as ApplicationStatus} />
                  </td>
                  <td className="admin-table__count">{row.attachment_count}</td>
                  <td>
                    <Link className="button button--secondary button--small" href={`/admin/applications/${row.id}?from=${encodeURIComponent(backTo)}`}>
                      عرض الملف
                      <span className="visually-hidden"> {row.reference_number}</span>
                    </Link>
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
