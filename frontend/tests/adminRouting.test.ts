/**
 * Where an account lands after signing in, and which addresses the
 * administration app answers (the server's list in routes/web.php).
 *
 * Run with `npm test` (Node's built-in test runner; Node strips the types).
 */
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { homeFor } from '../src/auth/useAuth.ts'
import { isAdminPath } from '../src/pages/admin/adminPaths.ts'

test('administrators land on /admin, judges on the judge portal, candidates on their dashboard', () => {
  const person = { is_candidate: true, admin_permissions: [], is_judge: false }
  assert.equal(homeFor(person), '/dashboard')
  assert.equal(homeFor({ ...person, is_judge: true }), '/judge', 'a judge is not sent to the candidate dashboard')
  assert.equal(homeFor({ ...person, admin_permissions: ['applications.view_submitted'], is_judge: true }), '/admin')
  assert.equal(homeFor({ is_candidate: false, admin_permissions: [], is_judge: false }), '/account')
})

test('only the real administration pages are handled by the admin app', () => {
  for (const path of ['/admin', '/admin/applications', '/admin/applications/42', '/admin/committees']) {
    assert.equal(isAdminPath(path), true, path)
  }
  for (const path of ['/admin/', '/admin/applications/abc', '/admin/schedule', '/admin/log', '/administrator']) {
    assert.equal(isAdminPath(path), false, path)
  }
})
