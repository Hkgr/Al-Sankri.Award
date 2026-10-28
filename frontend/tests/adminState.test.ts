/**
 * The administration dashboard's two binding rules: a judge confirmation
 * belongs to the account that was shown, and the applications table's
 * request always uses the cycle scope the header and the address show.
 *
 * Run with `npm test` (Node's built-in test runner; Node strips the types).
 */
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { tableQuery, tableScope, withScope } from '../src/pages/admin/applications/tableState.ts'
import { existingAppointment, isCurrentLookup } from '../src/pages/admin/committees/judgeLookup.ts'

test('a delayed lookup for A is dropped once the field holds B, and a confirmation sends the shown account only', () => {
  // Lookup 1 asked for A; the administrator then typed B (which invalidates lookup 1).
  assert.equal(isCurrentLookup(1, 2, 'a@example.org', 'b@example.org'), false, 'an older lookup')
  assert.equal(isCurrentLookup(2, 2, 'a@example.org', 'b@example.org'), false, 'an answer for another email')
  assert.equal(isCurrentLookup(2, 2, ' A@Example.org ', 'a@example.org'), true)

  const shown = { account_ref: 'f'.repeat(64), email: 'a@example.org' }
  assert.deepEqual(existingAppointment(shown), { existing: true, email: 'a@example.org', account_ref: shown.account_ref })
})

const cycles = [
  { id: 2, domains: [{ code: 'heritage', subcategories: [{ code: 'tangible_heritage' }] }, { code: 'creativity', subcategories: [{ code: 'quality' }] }] },
  { id: 1, domains: [{ code: 'heritage', subcategories: [{ code: 'old_heritage' }] }] },
]

test('changing the header cycle updates the address, and the request matches it', () => {
  const before = '?cycle=1&domain=heritage&subcategory=old_heritage&status=submitted&page=3'
  const after = `?${withScope(before, '2', cycles)}`
  assert.equal(tableScope(after, 1, cycles), '2', 'the address now names cycle 2, whatever was selected before')
  assert.equal(tableQuery(after, tableScope(after, 1, cycles)), 'cycle=2&domain=heritage&status=submitted', 'page reset; subcategory not in cycle 2 dropped')

  // A domain the new cycle lacks goes too.
  assert.equal(new URLSearchParams(withScope('?cycle=2&domain=creativity&subcategory=quality', '1', cycles)).get('domain'), null)
})

test('a restored address (back, forward, return link) decides the scope, and «all» stays explicit', () => {
  assert.equal(tableQuery('?cycle=1&q=خط', tableScope('?cycle=1&q=خط', 2, cycles)), 'cycle=1&q=%D8%AE%D8%B7')
  assert.equal(tableScope('', 2, cycles), '2', 'no cycle in the address: the selected cycle')
  assert.equal(tableScope('?cycle=99', 2, cycles), '2', 'an unknown cycle: the selected cycle')

  const all = `?${withScope('?cycle=1&domain=creativity', 'all', cycles)}`
  assert.equal(new URLSearchParams(all).get('cycle'), 'all')
  assert.equal(tableScope(all, 2, cycles), 'all')
  assert.equal(tableQuery(all, 'all'), 'domain=creativity', 'no cycle filter for «all»; a domain some cycle has is kept')
})
