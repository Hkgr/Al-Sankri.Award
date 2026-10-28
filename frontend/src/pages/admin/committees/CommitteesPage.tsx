import { useCallback, useEffect, useId, useRef, useState } from 'react'
import type { KeyboardEvent, ReactNode } from 'react'
import type { User } from '../../../auth/useAuth'
import { FormAlert, StatusIcon, TextField } from '../../../components/forms/FormControls'
import { ApiError, api, errorMessage } from '../../../lib/api'
import { usePageTitle } from '../../auth/usePageTitle'
import { formatDamascusDateTime } from '../../candidate/candidateApi'
import { useAccountFailure } from '../../candidate/useCandidateRequests'
import type { AdminCycle, Committee, CommitteeMember, CommitteesResponse, DomainCode, DomainCommittee } from '../adminApi'
import { cycleLabel } from '../adminApi'
import { AddJudgeDialog } from './AddJudgeDialog'
import { ExportMenu } from '../ExportMenu'
import { AdminDialog, ConfirmDialog } from './AdminDialog'

type Notice = { tone: 'success' | 'error' | 'info'; content: ReactNode }

type Pending =
  | { kind: 'create'; domain: DomainCode }
  | { kind: 'approve'; domain: DomainCode }
  | { kind: 'draft'; domain: DomainCode }
  | { kind: 'end'; domain: DomainCode; member: CommitteeMember }

/**
 * «لجان الحكام»: the three main-domain judging committees of the selected
 * cycle, one tab each. A committee is created only when asked, assembled
 * as a draft, and approved with exactly 3 or 5 current judges; an approved
 * committee returns to draft explicitly before its membership changes.
 */
export function CommitteesPage({ user, cycle }: { user: User; cycle: AdminCycle | null }) {
  usePageTitle('لجان الحكام')
  const handleAccountFailure = useAccountFailure()
  const [committees, setCommittees] = useState<DomainCommittee[] | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [active, setActive] = useState<DomainCode>('culture_and_arts')
  const [notice, setNotice] = useState<Notice | null>(null)
  const [pending, setPending] = useState<Pending | null>(null)
  const [committeeName, setCommitteeName] = useState('')
  const [busy, setBusy] = useState(false)
  const [adding, setAdding] = useState(false)
  const [linkBusy, setLinkBusy] = useState<number | null>(null)
  const tabs = useRef<(HTMLButtonElement | null)[]>([])
  const baseId = useId()

  const load = useCallback(async () => {
    if (!cycle) return
    setLoadError(null)
    try {
      const response = await api.get<CommitteesResponse>(`/api/admin/cycles/${cycle.id}/committees`)
      setCommittees(response.data)
      setActive((current) => (response.data.some((item) => item.domain.code === current) ? current : (response.data[0]?.domain.code ?? current)))
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
        <h1 className="admin-page__title">لجان الحكام</h1>
        <FormAlert tone="info">
          <p>لا توجد دورة جائزة مُعدّة بعد. تُنشأ اللجان لدورة محدّدة.</p>
        </FormAlert>
      </div>
    )
  }

  const post = async (path: string, body: Record<string, unknown> = {}) =>
    api.post<CommitteesResponse>(`/api/admin/cycles/${cycle.id}${path}`, { ...body, account_context: user.account_context })

  const run = async (action: () => Promise<CommitteesResponse>) => {
    setBusy(true)
    setNotice(null)
    try {
      const response = await action()
      setCommittees(response.data)
      setNotice({ tone: 'success', content: <p>{response.message}</p> })
    } catch (error) {
      if (!handleAccountFailure(error)) setNotice({ tone: 'error', content: <p>{errorMessage(error)}</p> })
      if (error instanceof ApiError && error.status === 409) void load()
    } finally {
      setBusy(false)
      setPending(null)
    }
  }

  const confirmPending = () => {
    if (!pending) return
    if (pending.kind === 'create') void run(() => post('/committees', { domain: pending.domain, name: committeeName }))
    if (pending.kind === 'approve') void run(() => post(`/committees/${pending.domain}/approve`))
    if (pending.kind === 'draft') void run(() => post(`/committees/${pending.domain}/return-to-draft`))
    if (pending.kind === 'end') void run(() => post(`/committees/${pending.domain}/members/${pending.member.id}/end`))
  }

  const askCreate = (domain: DomainCode) => {
    const name = committees?.find((item) => item.domain.code === domain)?.domain.name ?? ''
    setCommitteeName(`لجنة تحكيم مجال ${name}`)
    setAdding(false)
    setPending({ kind: 'create', domain })
  }

  const resendLink = async (domain: DomainCode, member: CommitteeMember) => {
    setLinkBusy(member.id)
    setNotice(null)
    try {
      const response = await api.post<{ message: string }>(`/api/admin/cycles/${cycle.id}/committees/${domain}/members/${member.id}/setup-link`, {
        account_context: user.account_context,
      })
      setNotice({ tone: 'success', content: <p>{response.message}</p> })
    } catch (error) {
      if (!handleAccountFailure(error)) setNotice({ tone: 'error', content: <p>{errorMessage(error)}</p> })
    } finally {
      setLinkBusy(null)
    }
  }

  const onTabKey = (event: KeyboardEvent, index: number) => {
    if (!committees) return
    // In RTL the next tab is to the left.
    const step = event.key === 'ArrowLeft' ? 1 : event.key === 'ArrowRight' ? -1 : 0
    const target = event.key === 'Home' ? 0 : event.key === 'End' ? committees.length - 1 : step ? (index + step + committees.length) % committees.length : null
    if (target === null) return
    event.preventDefault()
    setActive(committees[target].domain.code)
    tabs.current[target]?.focus()
  }

  const pendingDomain = committees?.find((item) => item.domain.code === pending?.domain)?.domain.name ?? ''

  return (
    <div className="admin-page">
      <header className="admin-page__header">
        <h1 className="admin-page__title">لجان الحكام</h1>
        <p className="admin-page__intro">
          {cycleLabel(cycle)}: لجنة تحكيم واحدة لكل مجال رئيسي، تشمل كل فئاته الفرعية. يُعتمد تشكيل اللجنة بثلاثة حكام أو خمسة بالضبط.
        </p>
      </header>

      {notice && (
        <div role={notice.tone === 'error' ? 'alert' : 'status'}>
          <FormAlert tone={notice.tone}>{notice.content}</FormAlert>
        </div>
      )}

      {loadError ? (
        <FormAlert tone="error">
          <p>{loadError}</p>
          <p>
            <button type="button" className="button button--secondary button--small" onClick={() => void load()}>
              إعادة المحاولة
            </button>
          </p>
        </FormAlert>
      ) : !committees ? (
        <p className="admin-loading" role="status">
          جارٍ تحميل اللجان…
        </p>
      ) : committees.length === 0 ? (
        <FormAlert tone="info">
          <p>لم تُعدّ المجالات الرئيسية لهذه الدورة بعد، فلا يمكن تشكيل لجانها.</p>
        </FormAlert>
      ) : (
        <>
          <div className="admin-tabs" role="tablist" aria-label="لجان المجالات الرئيسية">
            {committees.map((item, index) => (
              <button
                key={item.domain.code}
                ref={(element) => {
                  tabs.current[index] = element
                }}
                type="button"
                role="tab"
                id={`${baseId}-tab-${item.domain.code}`}
                aria-selected={active === item.domain.code}
                aria-controls={`${baseId}-panel-${item.domain.code}`}
                tabIndex={active === item.domain.code ? 0 : -1}
                className="admin-tab"
                onClick={() => setActive(item.domain.code)}
                onKeyDown={(event) => onTabKey(event, index)}
              >
                <span className="admin-tab__name">{item.domain.name}</span>
                <span className="admin-tab__meta">
                  {item.committee ? `${item.committee.status === 'approved' ? 'معتمدة' : 'مسودة'} · ${item.committee.current_count} حكام` : 'لم تُنشأ بعد'}
                </span>
              </button>
            ))}
          </div>
          {committees.map((item) => (
            <div
              key={item.domain.code}
              role="tabpanel"
              id={`${baseId}-panel-${item.domain.code}`}
              aria-labelledby={`${baseId}-tab-${item.domain.code}`}
              hidden={active !== item.domain.code}
              className="admin-panel"
              tabIndex={0}
            >
              {item.committee ? (
                <CommitteePanel
                  domain={item}
                  committee={item.committee}
                  busy={busy}
                  linkBusy={linkBusy}
                  onAdd={() => setAdding(true)}
                  onApprove={() => setPending({ kind: 'approve', domain: item.domain.code })}
                  onReturnToDraft={() => setPending({ kind: 'draft', domain: item.domain.code })}
                  onEnd={(member) => setPending({ kind: 'end', domain: item.domain.code, member })}
                  onResendLink={(member) => void resendLink(item.domain.code, member)}
                  exportMenu={
                    <ExportMenu user={user} url={(format) => `/api/admin/exports/committee-members?format=${format}&cycle=${cycle.id}&domain=${item.domain.code}`} />
                  }
                />
              ) : (
                <div className="admin-empty">
                  <p className="admin-empty__title">لا توجد لجنة تحكيم لمجال «{item.domain.name}» في هذه الدورة.</p>
                  <p>تُنشأ اللجنة مسودةً، ثم يُضاف إليها الحكام تدريجياً.</p>
                  <button type="button" className="button button--primary" onClick={() => askCreate(item.domain.code)}>
                    إنشاء اللجنة
                  </button>
                </div>
              )}
            </div>
          ))}

          <AddJudgeDialog
            key={`${adding}-${active}`}
            open={adding}
            user={user}
            cycleId={cycle.id}
            committees={committees}
            initialDomain={active}
            onClose={() => setAdding(false)}
            onCreateCommittee={askCreate}
            onDone={(response) => {
              setAdding(false)
              setCommittees(response.data)
              setNotice({ tone: response.appointment.setup_link === 'failed' ? 'info' : 'success', content: <p>{response.message}</p> })
            }}
          />

          <AdminDialog
            open={pending?.kind === 'create'}
            title={`إنشاء لجنة تحكيم مجال ${pendingDomain}`}
            titleId="create-committee-title"
            onClose={() => setPending(null)}
          >
            <form
              className="admin-dialog__body admin-form"
              onSubmit={(event) => {
                event.preventDefault()
                confirmPending()
              }}
            >
              <p>تُنشأ اللجنة مسودةً لهذه الدورة، بلا أعضاء. لا تُنشأ أي لجنة أخرى.</p>
              <TextField id="committee-name" label="اسم اللجنة" value={committeeName} onChange={setCommitteeName} required maxLength={255} autoComplete="off" />
              <div className="admin-dialog__actions">
                <button type="button" className="button button--secondary" onClick={() => setPending(null)}>
                  تراجع
                </button>
                <button type="submit" className="button button--primary" disabled={busy || !committeeName.trim()}>
                  {busy ? 'جارٍ الإنشاء…' : 'إنشاء اللجنة'}
                </button>
              </div>
            </form>
          </AdminDialog>

          <ConfirmDialog
            open={pending !== null && pending.kind !== 'create'}
            busy={busy}
            onClose={() => setPending(null)}
            onConfirm={confirmPending}
            title={pending?.kind === 'approve' ? 'اعتماد اللجنة' : pending?.kind === 'draft' ? 'إعادة اللجنة إلى المسودة' : 'إنهاء العضوية'}
            confirmLabel={pending?.kind === 'approve' ? 'اعتماد اللجنة' : pending?.kind === 'draft' ? 'إعادة إلى المسودة' : 'إنهاء العضوية'}
            text={
              pending?.kind === 'approve' ? (
                <p>ستُعتمد لجنة مجال «{pendingDomain}» بأعضائها الحاليين. لا تتغيّر عضويتها بعد الاعتماد إلا بإعادتها إلى المسودة.</p>
              ) : pending?.kind === 'draft' ? (
                <p>ستعود اللجنة إلى المسودة، فتُتاح إضافة الحكام وإنهاء عضوياتهم، ثم يلزم اعتمادها من جديد.</p>
              ) : pending?.kind === 'end' ? (
                <p>
                  ستنتهي عضوية <bdi>{pending.member.name}</bdi> في هذه اللجنة. تبقى العضوية في سجل اللجنة، ولا يُحذف الحساب.
                </p>
              ) : null
            }
          />
        </>
      )}
    </div>
  )
}

function CommitteePanel({
  domain,
  committee,
  busy,
  linkBusy,
  onAdd,
  onApprove,
  onReturnToDraft,
  onEnd,
  onResendLink,
  exportMenu,
}: {
  domain: DomainCommittee
  committee: Committee
  busy: boolean
  linkBusy: number | null
  onAdd: () => void
  onApprove: () => void
  onReturnToDraft: () => void
  onEnd: (member: CommitteeMember) => void
  onResendLink: (member: CommitteeMember) => void
  exportMenu: ReactNode
}) {
  const draft = committee.status === 'draft'
  const sizeOk = committee.allowed_sizes.includes(committee.current_count)
  const requirementId = useId()

  return (
    <>
      <div className="admin-committee__head">
        <div>
          <h2 className="admin-committee__name">
            <bdi>{committee.name}</bdi>
          </h2>
          <p className="admin-committee__meta">
            <span className="admin-state" data-state={committee.status}>
              {draft ? 'مسودة' : 'معتمدة'}
            </span>
            <span>المجال الرئيسي: {domain.domain.name}</span>
            <span>الحكام الحاليون: {committee.current_count}</span>
            {committee.approved_at && <span>اعتُمدت في {formatDamascusDateTime(committee.approved_at)}</span>}
          </p>
        </div>
        <div className="admin-committee__actions">
          {exportMenu}
          {draft ? (
            <>
              <button type="button" className="button button--secondary" onClick={onAdd} disabled={busy}>
                إضافة حكَم
              </button>
              <button type="button" className="button button--primary" onClick={onApprove} disabled={busy || !sizeOk} aria-describedby={requirementId}>
                اعتماد اللجنة
              </button>
            </>
          ) : (
            <button
              type="button"
              className="button button--secondary"
              onClick={onReturnToDraft}
              disabled={busy || committee.has_judging_history}
              aria-describedby={requirementId}
            >
              إعادة إلى المسودة
            </button>
          )}
        </div>
      </div>
      <p id={requirementId} className="admin-requirement" data-ok={sizeOk}>
        <StatusIcon tone={sizeOk ? 'success' : 'info'} />
        <span>
          {draft
            ? sizeOk
              ? `يمكن اعتماد اللجنة: فيها ${committee.current_count} حكام حاليين.`
              : `يتطلّب الاعتماد 3 أو 5 حكام حاليين بالضبط؛ في اللجنة الآن ${committee.current_count}.`
            : committee.has_judging_history
              ? 'للجنة سجل تحكيم قائم، فلا تُعاد إلى المسودة.'
              : 'اللجنة معتمدة. لتغيير أعضائها أعدها إلى المسودة أولاً، ثم اعتمدها من جديد.'}
        </span>
      </p>

      {committee.members.length === 0 ? (
        <div className="admin-empty">
          <p className="admin-empty__title">لا يوجد حكام في هذه اللجنة بعد.</p>
          {draft && <p>أضف الحكام واحداً تلو الآخر، ثم اعتمد اللجنة.</p>}
        </div>
      ) : (
        <div className="admin-table-wrap" tabIndex={0} role="region" aria-label={`أعضاء ${committee.name}`}>
          <table className="admin-table">
            <thead>
              <tr>
                <th scope="col">الاسم</th>
                <th scope="col">البريد الإلكتروني</th>
                <th scope="col">الهاتف</th>
                <th scope="col">التخصص</th>
                <th scope="col">المجال الرئيسي</th>
                <th scope="col">حالة العضوية والحساب</th>
                <th scope="col">
                  <span className="visually-hidden">الإجراءات</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {committee.members.map((member) => (
                <tr key={member.id}>
                  <td>
                    <bdi>{member.name}</bdi>
                  </td>
                  <td>
                    <bdi dir="ltr">{member.email}</bdi>
                  </td>
                  <td>{member.phone ? <bdi dir="ltr">{member.phone}</bdi> : '—'}</td>
                  <td>{member.specialty ?? '—'}</td>
                  <td>{member.domain}</td>
                  <td>
                    <ul className="admin-flags">
                      <li>عضو منذ {formatDamascusDateTime(member.joined_at)}</li>
                      <li data-tone={member.account.active ? undefined : 'warn'}>{member.account.active ? 'الحساب نشط' : 'الحساب معطّل'}</li>
                      {member.account.setup_pending && <li data-tone="warn">بانتظار تعيين كلمة المرور</li>}
                      {!member.account.email_verified && <li>البريد غير مؤكَّد</li>}
                    </ul>
                  </td>
                  <td className="admin-table__actions">
                    {member.account.setup_pending && member.account.active && (
                      <button type="button" className="button button--quiet button--small" onClick={() => onResendLink(member)} disabled={linkBusy === member.id}>
                        {linkBusy === member.id ? 'جارٍ الإرسال…' : 'إعادة إرسال رابط الإعداد'}
                        <span className="visually-hidden"> إلى {member.name}</span>
                      </button>
                    )}
                    {draft && (
                      <button type="button" className="button button--quiet button--small" onClick={() => onEnd(member)} disabled={busy}>
                        إنهاء العضوية
                        <span className="visually-hidden"> {member.name}</span>
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {committee.former_members.length > 0 && (
        <details className="admin-history">
          <summary>العضويات المنتهية ({committee.former_members.length})</summary>
          <ul>
            {committee.former_members.map((member) => (
              <li key={member.id}>
                <bdi>{member.name}</bdi> (<bdi dir="ltr">{member.email}</bdi>): من {formatDamascusDateTime(member.joined_at)} إلى{' '}
                {member.left_at ? formatDamascusDateTime(member.left_at) : '—'}
              </li>
            ))}
          </ul>
        </details>
      )}
    </>
  )
}
