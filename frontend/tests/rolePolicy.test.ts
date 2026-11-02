import assert from 'node:assert/strict'
import { test } from 'node:test'
import { homeFor } from '../src/auth/useAuth.ts'
import { ar, en } from '../src/i18n/catalogs/rolePolicy.ts'

test('role policy messages explain the default and all other assignments in Arabic and English', () => {
  for (const key of Object.keys(ar)) {
    assert.ok(en[key], key)
    assert.doesNotMatch(en[key], /[\u0600-\u06ff]/, key)
  }
  assert.match(en['roles.other'], /all such assignments end/)
  assert.match(en['roles.other'], /data remains saved/)
})

test('the account with a custom role has an account destination until the server restores candidate', () => {
  const assigned = { is_candidate: false, is_judge: false, admin_permissions: [] }
  assert.equal(homeFor(assigned), '/account')
  assert.equal(homeFor({ ...assigned, is_candidate: true }), '/dashboard')
  assert.equal(homeFor({ ...assigned, admin_permissions: ['users.view_registered'] }), '/admin')
})
