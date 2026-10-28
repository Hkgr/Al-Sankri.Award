/**
 * The «المستخدمون» table's state in the page address. Progress is shown for
 * ONE cycle, so the scope is always a specific cycle (never «all»): the
 * address's cycle when it names a known one, otherwise the selected cycle,
 * or none when no cycle exists. The header's cycle choice changes it
 * through withUsersCycle(), so header, address and request agree. Kept free
 * of imports so the Node test runner can load it directly.
 */

export const USERS_PARAMS = ['cycle', 'q', 'role', 'state', 'verified', 'progress', 'sort', 'direction', 'page', 'per_page'] as const

export function usersScope(search: string, selectedCycleId: number | null, cycleIds: number[]): number | null {
  const value = Number(new URLSearchParams(search).get('cycle'))
  if (Number.isInteger(value) && cycleIds.includes(value)) return value
  return selectedCycleId !== null && cycleIds.includes(selectedCycleId) ? selectedCycleId : null
}

/** The request's query: the address's values, with the scope as its cycle. */
export function usersQuery(search: string, scope: number | null): string {
  const params = new URLSearchParams(search)
  const query = new URLSearchParams()
  for (const name of USERS_PARAMS) {
    const value = name === 'cycle' ? (scope === null ? null : String(scope)) : params.get(name)
    if (value) query.set(name, value)
  }
  return query.toString()
}

/** The address after choosing another cycle: back to page 1; every account stays listed. */
export function withUsersCycle(search: string, cycleId: number): string {
  const next = new URLSearchParams(search)
  next.delete('page')
  next.set('cycle', String(cycleId))
  return next.toString()
}
