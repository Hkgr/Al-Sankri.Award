import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../../auth/useAuth'
import type { AdminPermission } from '../../auth/useAuth'
import { FormAlert } from '../../components/forms/FormControls'
import { api, errorMessage } from '../../lib/api'
import { Link } from '../../lib/router'
import { useRouter } from '../../lib/useRouter'
import { AuthLayout } from '../auth/AuthLayout'
import { AdminHomePage } from './AdminHomePage'
import { AdminLayout } from './AdminLayout'
import type { AdminContext } from './adminApi'
import { ApplicationPage } from './applications/ApplicationPage'
import { ApplicationsPage } from './applications/ApplicationsPage'
import { ALL_CYCLES, tableScope, withScope } from './applications/tableState'
import { CommitteesPage } from './committees/CommitteesPage'
import { LogPage } from './log/LogPage'
import { SchedulePage } from './schedule/SchedulePage'
import { UsersPage } from './users/UsersPage'
import { usersScope, withUsersCycle } from './users/usersState'

const CYCLE_KEY = 'sa-admin-cycle'

function storedCycle(): number | null {
  try {
    const value = Number(window.localStorage.getItem(CYCLE_KEY))
    return Number.isInteger(value) && value > 0 ? value : null
  } catch {
    return null
  }
}

/**
 * /admin and its pages. Signed-out visitors sign in first and come back;
 * accounts without administration access see a refusal (the server refuses
 * every admin request anyway). The selected award cycle is remembered in
 * this browser only, as a convenience.
 */
export function AdminApp() {
  const { user } = useAuth()
  const { location, navigate } = useRouter()
  const [context, setContext] = useState<AdminContext | null>(null)
  const [contextError, setContextError] = useState<string | null>(null)
  const [cycleId, setCycleId] = useState<number | null>(storedCycle)
  const isAdmin = (user?.admin_permissions.length ?? 0) > 0

  useEffect(() => {
    if (user === null) navigate(`/login?redirect=${encodeURIComponent(location.pathname + location.search)}`, { replace: true })
  }, [user, navigate, location.pathname, location.search])

  const loadContext = useCallback(async () => {
    setContextError(null)
    try {
      setContext((await api.get<{ data: AdminContext }>('/api/admin/context')).data)
    } catch (error) {
      setContextError(errorMessage(error))
    }
  }, [])

  useEffect(() => {
    // Fetching on mount: the state is set once the request settles.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    if (isAdmin) void loadContext()
  }, [isAdmin, loadContext, user?.account_context])

  useEffect(() => {
    if (cycleId === null) return
    try {
      window.localStorage.setItem(CYCLE_KEY, String(cycleId))
    } catch {
      // Not remembered in this browser; the selection still applies here.
    }
  }, [cycleId])

  const cycles = context?.cycles ?? []
  const cycle = cycles.find((item) => item.id === cycleId) ?? cycles.find((item) => item.id === context?.default_cycle_id) ?? cycles[0] ?? null
  const onTable = location.pathname === '/admin/applications'
  // On «ملفات الترشح» the address holds the scope; the header shows that same scope.
  const scope = onTable ? tableScope(location.search, cycle?.id ?? null, cycles) : null

  // On «المستخدمون» the address holds one specific cycle (progress is per cycle).
  const onUsers = location.pathname === '/admin/users'
  const usersCycle = onUsers ? usersScope(location.search, cycle?.id ?? null, cycles.map((item) => item.id)) : null
  const addressCycle = scope && scope !== ALL_CYCLES ? Number(scope) : usersCycle

  if (addressCycle !== null && addressCycle !== cycleId) {
    // The address names another cycle (back/forward, a return link): the
    // header and the remembered selection follow it. No navigation here.
    setCycleId(addressCycle)
  }

  /** The header or a page's cycle filter: one change, one address, one request. */
  const changeCycle = (value: string) => {
    if (value !== ALL_CYCLES) setCycleId(Number(value))
    if (onTable && value !== scope) navigate(`/admin/applications?${withScope(location.search, value, cycles)}`, { keepPosition: true })
    if (onUsers && value !== ALL_CYCLES && Number(value) !== usersCycle) navigate(`/admin/users?${withUsersCycle(location.search, Number(value))}`, { keepPosition: true })
  }

  if (!user) {
    return (
      <main id="main" tabIndex={-1}>
        <p className="auth-loading" role="status">
          جارٍ التحقق من الجلسة…
        </p>
      </main>
    )
  }

  if (!isAdmin) {
    return (
      <main id="main" tabIndex={-1}>
        <AuthLayout title="لوحة الإدارة">
          <p className="auth-panel__text">هذه اللوحة مخصّصة لإدارة الجائزة، وحسابك لا يملك صلاحية الوصول إليها.</p>
          <p className="auth-panel__switch">
            <Link href="/account">الانتقال إلى حسابي</Link>
          </p>
        </AuthLayout>
      </main>
    )
  }

  const can = (permission: AdminPermission) => user.admin_permissions.includes(permission)
  const detail = /^\/admin\/applications\/(\d+)$/.exec(location.pathname)

  let page
  if (contextError) {
    page = (
      <FormAlert tone="error">
        <p>{contextError}</p>
        <p>
          <button type="button" className="button button--secondary" onClick={() => void loadContext()}>
            إعادة المحاولة
          </button>
        </p>
      </FormAlert>
    )
  } else if (!context) {
    page = (
      <p className="admin-loading" role="status">
        جارٍ تحميل لوحة الإدارة…
      </p>
    )
  } else if (location.pathname === '/admin/log' && (can('audit.view') || can('logs.view_system'))) {
    page = <LogPage key={user.account_context} user={user} cycles={cycles} />
  } else if (onUsers && can('users.view_registered')) {
    page = <UsersPage key={user.account_context} user={user} cycles={cycles} scope={usersCycle} />
  } else if (location.pathname === '/admin/applications' && can('applications.view_submitted')) {
    page = <ApplicationsPage user={user} cycles={cycles} scope={scope ?? ALL_CYCLES} onScopeChange={changeCycle} />
  } else if (detail && can('applications.view_submitted')) {
    page = <ApplicationPage applicationId={Number(detail[1])} />
  } else if (location.pathname === '/admin/committees' && can('committees.manage_judging')) {
    page = <CommitteesPage key={`${user.account_context}-${cycle?.id ?? 0}`} user={user} cycle={cycle} />
  } else if (location.pathname === '/admin/schedule' && can('schedules.manage')) {
    page = <SchedulePage key={`${user.account_context}-${cycle?.id ?? 0}`} user={user} cycle={cycle} />
  } else if (location.pathname === '/admin') {
    page = <AdminHomePage user={user} cycle={cycle} />
  } else {
    page = (
      <FormAlert tone="info">
        <p>لا يملك حسابك صلاحية هذا القسم.</p>
      </FormAlert>
    )
  }

  return (
    <AdminLayout user={user} cycles={cycles} cycleValue={onTable ? scope : onUsers ? (usersCycle === null ? null : String(usersCycle)) : cycle ? String(cycle.id) : null} includeAll={onTable} onCycleChange={changeCycle}>
      {page}
    </AdminLayout>
  )
}
