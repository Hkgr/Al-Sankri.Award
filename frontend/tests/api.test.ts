/**
 * The shared API client's deadlines (src/lib/api.ts), with a stub fetch and
 * fake timers: nothing here waits for real time.
 *
 * Run with `npm test` (Node's built-in test runner; Node strips the types).
 */
import assert from 'node:assert/strict'
import { afterEach, beforeEach, mock, test } from 'node:test'
import { api, ApiError, NetworkError, REQUEST_TIMEOUT_MS, RequestTimeoutError, UPLOAD_TIMEOUT_MS } from '../src/lib/api.ts'

type Call = { url: string; method: string; signal: AbortSignal; headers: Record<string, string>; body: unknown }

let calls: Call[] = []
let replies: ((call: Call) => Promise<Response>)[] = []

const cookies = { cookie: '' }

beforeEach(() => {
  calls = []
  replies = []
  cookies.cookie = 'XSRF-TOKEN=token-1'
  Object.defineProperty(globalThis, 'document', { value: cookies, configurable: true })
  globalThis.fetch = (async (input: RequestInfo | URL, init?: RequestInit) => {
    const call = {
      url: String(input),
      method: init?.method ?? 'GET',
      signal: init?.signal as AbortSignal,
      headers: (init?.headers ?? {}) as Record<string, string>,
      body: init?.body,
    }
    calls.push(call)
    const reply = replies.shift()
    assert.ok(reply, `unexpected request ${call.method} ${call.url}`)
    return reply(call)
  }) as typeof fetch
  mock.timers.enable({ apis: ['setTimeout'] })
})

afterEach(() => {
  mock.timers.reset()
})

/** Lets pending promise callbacks run (setImmediate is not faked). */
const settle = () => new Promise((resolve) => setImmediate(resolve))

/** A fetch that never answers, but rejects when aborted (as a browser's does). */
const noHeaders = (call: Call) =>
  new Promise<Response>((_, reject) => call.signal.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError'))))

/** Headers arrive; the body starts and never finishes (and ignores the abort). */
const unfinishedBody = (status: number) => async () =>
  new Response(
    new ReadableStream({
      start(controller) {
        controller.enqueue(new TextEncoder().encode('{"data": {"title": "'))
      },
    }),
    { status, headers: { 'Content-Type': 'application/json' } },
  )

const json = (status: number, body: unknown) => async () => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })

/** Starts a request and records how it settles, without awaiting it. */
function track<T>(promise: Promise<T>) {
  const state: { settled: boolean; value?: T; error?: unknown } = { settled: false }
  promise.then(
    (value) => Object.assign(state, { settled: true, value }),
    (error) => Object.assign(state, { settled: true, error }),
  )
  return state
}

test('no response headers: times out at the deadline, not before, and aborts the request', async () => {
  replies.push(noHeaders)
  const state = track(api.get('/api/candidate/nomination-details/journey'))
  await settle()

  mock.timers.tick(REQUEST_TIMEOUT_MS - 1)
  await settle()
  assert.equal(state.settled, false)

  mock.timers.tick(1)
  await settle()
  assert.ok(state.error instanceof RequestTimeoutError)
  assert.ok(state.error instanceof NetworkError, 'callers treat it as a lost answer')
  assert.equal(calls[0].signal.aborted, true)
})

test('headers received but the JSON body never finishes: times out', async () => {
  replies.push(unfinishedBody(201))
  const state = track(api.post('/api/candidate/nomination-details', { title: 'x' }))
  await settle()
  assert.equal(state.settled, false)

  mock.timers.tick(REQUEST_TIMEOUT_MS)
  await settle()
  assert.ok(state.error instanceof RequestTimeoutError)
  assert.equal(calls.length, 1, 'never sent twice')
})

test('an error answer whose body never finishes: times out', async () => {
  replies.push(unfinishedBody(500))
  const state = track(api.post('/api/candidate/nomination-details', {}))
  await settle()
  mock.timers.tick(REQUEST_TIMEOUT_MS)
  await settle()
  assert.ok(state.error instanceof RequestTimeoutError)
})

test('a stalled CSRF cookie request times out and the write is never sent', async () => {
  cookies.cookie = ''
  replies.push(noHeaders)
  const state = track(api.post('/api/candidate/basic-profile', {}))
  await settle()
  mock.timers.tick(REQUEST_TIMEOUT_MS)
  await settle()
  assert.ok(state.error instanceof RequestTimeoutError)
  assert.deepEqual(calls.map((call) => call.url), ['/sanctum/csrf-cookie'])
})

test('a 419 retry is bounded: a stalled refresh, then a stalled resend, each time out', async () => {
  replies.push(json(419, { message: 'expired' }), noHeaders)
  const refresh = track(api.post('/api/candidate/nomination-details', {}))
  await settle()
  mock.timers.tick(REQUEST_TIMEOUT_MS)
  await settle()
  assert.ok(refresh.error instanceof RequestTimeoutError)
  assert.deepEqual(calls.map((call) => call.url), ['/api/candidate/nomination-details', '/sanctum/csrf-cookie'])

  calls = []
  replies.push(json(419, { message: 'expired' }), async () => new Response(null, { status: 204 }), noHeaders)
  const resend = track(api.post('/api/candidate/nomination-details', {}))
  await settle()
  mock.timers.tick(REQUEST_TIMEOUT_MS)
  await settle()
  assert.ok(resend.error instanceof RequestTimeoutError)
  assert.equal(calls.length, 3, 'one resend only')
})

test('answers within the deadline behave as before', async () => {
  replies.push(json(200, { data: { ok: true } }))
  assert.deepEqual(await api.get('/api/user', { 'X-Candidate-Context': 'ctx' }), { data: { ok: true } })
  assert.equal(calls[0].headers['X-Candidate-Context'], 'ctx')

  replies.push(async () => new Response(null, { status: 204 }))
  assert.equal(await api.post('/api/candidate/nomination-details/check', {}), undefined)

  replies.push(json(422, { message: 'invalid', errors: { title: ['required'] } }))
  await assert.rejects(api.post('/api/x', {}), (error: unknown) => error instanceof ApiError && error.status === 422 && error.errors.title[0] === 'required')

  replies.push(json(409, { code: 'nomination_details_already_confirmed', message: 'stored', data: { title: 't' } }))
  await assert.rejects(api.post('/api/x', {}), (error: unknown) => error instanceof ApiError && error.code === 'nomination_details_already_confirmed' && (error.payload.data as { title: string }).title === 't')

  // The 419 retry still happens once.
  calls = []
  replies.push(json(419, {}), async () => new Response(null, { status: 204 }), json(201, { data: 1 }))
  assert.deepEqual(await api.post('/api/x', {}), { data: 1 })
  assert.deepEqual(calls.map((call) => call.url), ['/api/x', '/sanctum/csrf-cookie', '/api/x'])

  // A rejected fetch is still a NetworkError, not a timeout.
  replies.push(async () => {
    throw new TypeError('Failed to fetch')
  })
  await assert.rejects(api.get('/api/user'), (error: unknown) => error instanceof NetworkError && !(error instanceof RequestTimeoutError))

  // Finished requests leave no deadline running.
  assert.doesNotThrow(() => mock.timers.tick(REQUEST_TIMEOUT_MS * 2))
})

test('a slow but finished body within the deadline is read', async () => {
  let finish: () => void = () => undefined
  replies.push(
    async () =>
      new Response(
        new ReadableStream({
          start(controller) {
            controller.enqueue(new TextEncoder().encode('{"data": '))
            finish = () => {
              controller.enqueue(new TextEncoder().encode('"done"}'))
              controller.close()
            }
          },
        }),
        { status: 200 },
      ),
  )
  const state = track(api.get('/api/candidate/nomination-details'))
  await settle()
  mock.timers.tick(REQUEST_TIMEOUT_MS - 1)
  finish()
  await settle()
  assert.deepEqual(state.value, { data: 'done' })
})

test('an upload sends multipart form data and has its own, longer deadline', async () => {
  replies.push(noHeaders)
  const form = new FormData()
  form.append('kind', 'video')
  const state = track(api.upload('/api/candidate/documentation/files', form))
  await settle()
  assert.equal(calls[0].body, form, 'the form is sent as it is')
  assert.equal(calls[0].headers['Content-Type'], undefined, 'the browser sets the multipart boundary')

  mock.timers.tick(REQUEST_TIMEOUT_MS)
  await settle()
  assert.equal(state.settled, false, 'not cut off at the ordinary deadline')

  mock.timers.tick(UPLOAD_TIMEOUT_MS - REQUEST_TIMEOUT_MS)
  await settle()
  assert.ok(state.error instanceof RequestTimeoutError)
})

test('a 413 from the server or a proxy gets an understandable message', async () => {
  replies.push(async () => new Response('<html>413 Request Entity Too Large</html>', { status: 413 }))
  await assert.rejects(api.upload('/api/candidate/documentation/files', new FormData()), (error: unknown) => error instanceof ApiError && error.status === 413 && error.message.includes('أكبر من الحد'))
})
