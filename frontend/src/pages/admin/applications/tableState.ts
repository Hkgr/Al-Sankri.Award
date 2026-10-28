/**
 * The «ملفات الترشح» table's state in the page address, and its cycle
 * scope: one cycle, or «كل الدورات». The header's cycle choice and the
 * toolbar's cycle filter both change it through withScope(), so the header,
 * the filters and the request always name the same scope. Kept free of
 * imports so the Node test runner can load it directly.
 */

export const ALL_CYCLES = 'all'

/** The table's query values, kept in the page address so returning restores them. */
export const TABLE_PARAMS = ['cycle', 'domain', 'subcategory', 'applicant_type', 'status', 'q', 'sort', 'direction', 'page', 'per_page'] as const

type CycleShape = { id: number; domains: { code: string; subcategories: { code: string }[] }[] }

/**
 * The scope in force: the address's cycle when it names a known cycle or
 * «all»; otherwise (no or unknown value) the selected cycle, or «all» when
 * there is none.
 */
export function tableScope(search: string, selectedCycleId: number | null, cycles: CycleShape[]): string {
  const value = new URLSearchParams(search).get('cycle')
  if (value === ALL_CYCLES) return ALL_CYCLES
  if (value && cycles.some((cycle) => String(cycle.id) === value)) return value
  return selectedCycleId !== null && cycles.some((cycle) => cycle.id === selectedCycleId) ? String(selectedCycleId) : ALL_CYCLES
}

/** The request's query: the address's table values, with the scope as its cycle (none for «all»). */
export function tableQuery(search: string, scope: string): string {
  const params = new URLSearchParams(search)
  const query = new URLSearchParams()
  for (const name of TABLE_PARAMS) {
    const value = name === 'cycle' ? (scope === ALL_CYCLES ? null : scope) : params.get(name)
    if (value) query.set(name, value)
  }
  return query.toString()
}

/**
 * The address's query after choosing another scope: back to page 1, and the
 * domain and subcategory kept only where the new scope has them.
 */
export function withScope(search: string, scope: string, cycles: CycleShape[]): string {
  const next = new URLSearchParams(search)
  const inScope = scope === ALL_CYCLES ? cycles : cycles.filter((cycle) => String(cycle.id) === scope)
  const domains = inScope.flatMap((cycle) => cycle.domains)
  const domain = next.get('domain')
  const matching = domains.filter((item) => item.code === domain)
  if (domain && matching.length === 0) {
    next.delete('domain')
    next.delete('subcategory')
  }
  const subcategory = next.get('subcategory')
  if (subcategory && !matching.some((item) => item.subcategories.some((sub) => sub.code === subcategory))) next.delete('subcategory')
  next.delete('page')
  next.set('cycle', scope)
  return next.toString()
}
