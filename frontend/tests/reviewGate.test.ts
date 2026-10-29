/**
 * The review gate on the client: the applications table keeps its
 * administrative-classification filter in the address and the request.
 *
 * Run with `npm test` (Node's built-in test runner; Node strips the types).
 */
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { TABLE_PARAMS, tableQuery, withScope } from '../src/pages/admin/applications/tableState.ts'

test('the classification filter stays in the table request and survives a scope change', () => {
  assert.ok(TABLE_PARAMS.includes('review'))
  assert.equal(tableQuery('?review=excluded&page=2', '7'), 'cycle=7&review=excluded&page=2')
  assert.equal(new URLSearchParams(withScope('?review=pending&page=3', 'all', [])).get('review'), 'pending')
})
