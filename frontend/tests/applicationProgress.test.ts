import test from 'node:test'
import assert from 'node:assert/strict'
import { progressCopy, progressUrl, candidateSignInUrl } from '../src/pages/candidate/progress/progressApi.ts'
import { ar, en } from '../src/i18n/catalogs/applicationProgress.ts'
import { api } from '../src/lib/api.ts'

test('all public stages share safe bilingual labels and event-bound mail copy', () => {
  const codes = ['received', 'administrative_review', 'committee_review', 'review_completed', 'tracking_paused']
  for (const locale of ['ar', 'en'] as const) {
    assert.deepEqual(Object.keys(progressCopy[locale]), codes)
    for (const [code, content] of Object.entries(progressCopy[locale])) {
      assert.ok(content.label)
      assert.ok(content.description)
      if (code !== 'received') assert.ok('subject' in content)
      assert.doesNotMatch(JSON.stringify(content), /excluded|winner|average|rejected|استبعاد|مستبعد|فائز|مرفوض|\d+\s*(?:of|من)\s*\d+|%/i)
    }
  }
  for (const key of Object.keys(ar)) assert.ok(en[key], key)
})

test('tracking reads bind an encoded journey and submitted version without selecting arbitrary IDs', () => {
  assert.equal(progressUrl(), '/api/candidate/progress')
  assert.equal(progressUrl('a+b/=', '2'), '/api/candidate/progress/journey?journey_key=a%2Bb%2F%3D&version=2')
  assert.equal(progressUrl(null, '1'), '/api/candidate/progress?version=1')
})

test('a cancelled progress read aborts its actual fetch rather than surviving navigation', async () => {
  const originalFetch = globalThis.fetch
  const originalDocument = Object.getOwnPropertyDescriptor(globalThis, 'document')
  Object.defineProperty(globalThis, 'document', { value: { cookie: '' }, configurable: true })
  let captured: AbortSignal | undefined
  globalThis.fetch = async (_url, init) => {
    captured = init?.signal as AbortSignal
    return await new Promise<Response>(() => {})
  }
  const controller = new AbortController()
  try {
    const request = api.get('/api/candidate/progress', {}, controller.signal)
    controller.abort()
    await assert.rejects(request, { name: 'AbortError' })
    assert.equal(captured?.aborted, true)
  } finally {
    globalThis.fetch = originalFetch
    if (originalDocument) Object.defineProperty(globalThis, 'document', originalDocument)
    else Reflect.deleteProperty(globalThis, 'document')
  }
})

test('the sign-in return target preserves the bound journey, version and progress anchor', () => {
  const location = { pathname: '/dashboard', search: '?journey_key=a%2Bb%2F%3D&version=2', hash: '#application-progress' }
  const url = new URL(candidateSignInUrl(location), 'https://award.example.org')
  assert.equal(url.pathname, '/login')
  assert.equal(url.searchParams.get('redirect'), '/dashboard?journey_key=a%2Bb%2F%3D&version=2#application-progress')
})
