/**
 * The countdown's boundaries and its independence from the visitor's
 * timezone. The schedule is the confirmed one: applications close at
 * 2026-11-16T00:00:00+03:00 (exclusive), judging runs until
 * 2026-11-26T00:00:00+03:00 (exclusive).
 *
 * Run with `npm test` (Node's built-in test runner; Node strips the types).
 */
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { applicationPhaseAt, clockOffset, countdownTarget, deadlineText, judgingPhaseAt, periodText, phaseAt, remaining } from '../src/lib/schedule.ts'
import type { PublicSchedule } from '../src/lib/schedule.ts'

const CLOSES = Date.parse('2026-11-16T00:00:00+03:00')
const JUDGING_ENDS = Date.parse('2026-11-26T00:00:00+03:00')
const OCTOBER_29 = Date.parse('2026-10-29T09:00:00+03:00')

const schedule = (open: boolean): PublicSchedule => ({
  server_time: '2026-11-01T09:00:00Z',
  cycle: { name: 'الدورة الأولى', year: 2026, timezone: 'Asia/Damascus' },
  application: { opens_at: null, closes_at: '2026-11-15T21:00:00Z', open },
  judging: { opens_at: '2026-11-15T21:00:00Z', closes_at: '2026-11-25T21:00:00Z' },
})

test('the confirmed instants are the Damascus midnights, whatever the device timezone', () => {
  assert.equal(CLOSES, Date.parse('2026-11-15T21:00:00Z'))
  assert.equal(JUDGING_ENDS, Date.parse('2026-11-25T21:00:00Z'))
})

test('the application phase ends exactly at the exclusive closing instant', () => {
  const open = schedule(true)
  assert.equal(phaseAt(CLOSES - 1000, open), 'open')
  assert.equal(phaseAt(CLOSES - 1, open), 'open')
  assert.equal(applicationPhaseAt(CLOSES - 1, open), 'open')
  assert.equal(applicationPhaseAt(CLOSES, open), 'closed')
  assert.equal(judgingPhaseAt(CLOSES - 1, open), 'upcoming')
  assert.equal(judgingPhaseAt(CLOSES, open), 'current')
  assert.equal(phaseAt(CLOSES, open), 'judging', 'closed at the instant itself, without a reload')
  assert.equal(phaseAt(JUDGING_ENDS - 1, open), 'judging')
  assert.equal(phaseAt(JUDGING_ENDS, open), 'judged')
  assert.equal(judgingPhaseAt(JUDGING_ENDS - 1, open), 'current')
  assert.equal(judgingPhaseAt(JUDGING_ENDS, open), 'ended')
  assert.equal(phaseAt(CLOSES, schedule(false)), 'judging')
  assert.equal(phaseAt(JUDGING_ENDS, schedule(false)), 'judged')
  assert.equal(countdownTarget('open', open), CLOSES)
  assert.equal(countdownTarget('judging', open), null)
})

test('the server decides: a closed stage is never reopened by the client clock', () => {
  assert.equal(phaseAt(CLOSES - 86_400_000, schedule(false)), 'unavailable')
  assert.equal(phaseAt(0, null), 'unknown')
  const notYet: PublicSchedule = { ...schedule(false), application: { opens_at: '2026-11-05T21:00:00Z', closes_at: '2026-11-15T21:00:00Z', open: false } }
  assert.equal(phaseAt(Date.parse('2026-11-05T20:00:00Z'), notYet), 'upcoming')
  assert.equal(countdownTarget('upcoming', notYet), Date.parse('2026-11-05T21:00:00Z'))
  assert.equal(phaseAt(Date.parse('2026-11-05T21:00:00Z'), notYet), 'unavailable', 'the client cannot reopen a server-refused period at its planned opening')
})

test('incomplete configuration before a future deadline is unavailable, not elapsed or judging', () => {
  const incomplete = schedule(false)
  assert.equal(applicationPhaseAt(OCTOBER_29, incomplete), 'unavailable')
  assert.equal(phaseAt(OCTOBER_29, incomplete), 'unavailable')
  assert.equal(judgingPhaseAt(OCTOBER_29, incomplete), 'upcoming')
  assert.equal(countdownTarget(phaseAt(OCTOBER_29, incomplete), incomplete), null)
  assert.equal(deadlineText(incomplete.application!.closes_at!), 'بنهاية يوم 15 نوفمبر 2026')
})

test('administrative closure after opening does not complete a future application period', () => {
  const administrativelyClosed: PublicSchedule = {
    ...schedule(false),
    application: { opens_at: '2026-10-01T00:00:00+03:00', closes_at: '2026-11-15T21:00:00Z', open: false },
  }
  assert.equal(applicationPhaseAt(OCTOBER_29, administrativelyClosed), 'unavailable')
  assert.equal(phaseAt(OCTOBER_29, administrativelyClosed), 'unavailable')
  assert.equal(phaseAt(CLOSES - 1, administrativelyClosed), 'unavailable')
  assert.equal(applicationPhaseAt(CLOSES, administrativelyClosed), 'closed')
  assert.equal(phaseAt(CLOSES, administrativelyClosed), 'judging')
})

test('unavailable applications with no judging window never imply judging', () => {
  const noJudging: PublicSchedule = { ...schedule(false), judging: null }
  assert.equal(phaseAt(OCTOBER_29, noJudging), 'unavailable')
  assert.equal(judgingPhaseAt(OCTOBER_29, noJudging), 'unknown')
  assert.equal(phaseAt(CLOSES, noJudging), 'closed')
  assert.equal(phaseAt(JUDGING_ENDS, noJudging), 'closed')
  const undated: PublicSchedule = { ...noJudging, application: { opens_at: null, closes_at: null, open: false } }
  assert.equal(phaseAt(JUDGING_ENDS, undated), 'unavailable')
})

test('judging requires both valid interval boundaries and cannot start during a gap', () => {
  const delayedStart = '2026-11-17T00:00:00+03:00'
  const delayed: PublicSchedule = { ...schedule(false), judging: { opens_at: delayedStart, closes_at: '2026-11-25T21:00:00Z' } }
  assert.equal(phaseAt(CLOSES, delayed), 'closed')
  assert.equal(judgingPhaseAt(CLOSES, delayed), 'upcoming')
  assert.equal(phaseAt(Date.parse(delayedStart), delayed), 'judging')
  for (const judging of [
    { opens_at: null, closes_at: '2026-11-25T21:00:00Z' },
    { opens_at: '2026-11-15T21:00:00Z', closes_at: null },
    { opens_at: 'not a date', closes_at: '2026-11-25T21:00:00Z' },
    { opens_at: '2026-11-25T21:00:00Z', closes_at: '2026-11-15T21:00:00Z' },
  ]) {
    const invalid: PublicSchedule = { ...schedule(false), judging }
    assert.equal(judgingPhaseAt(CLOSES, invalid), 'unknown')
    assert.equal(phaseAt(CLOSES, invalid), 'closed')
  }
})

test('the remaining time is computed from now, split into labelled units and clamped at zero', () => {
  assert.deepEqual(remaining(CLOSES - 1000, CLOSES), { days: 0, hours: 0, minutes: 0, seconds: 1, totalMs: 1000 })
  assert.deepEqual(remaining(CLOSES - (3 * 86_400 + 4 * 3_600 + 5 * 60 + 6) * 1000, CLOSES), { days: 3, hours: 4, minutes: 5, seconds: 6, totalMs: 273_906_000 })
  assert.deepEqual(remaining(CLOSES + 5000, CLOSES), { days: 0, hours: 0, minutes: 0, seconds: 0, totalMs: 0 })
  assert.equal(remaining(CLOSES - 999, CLOSES).seconds, 0, 'a partial second is not rounded up')
})

test('the announced dates are the Damascus calendar days, under any device timezone', () => {
  for (const zone of ['UTC', 'America/Los_Angeles', 'Pacific/Kiritimati', 'Asia/Tokyo', 'Asia/Damascus']) {
    process.env.TZ = zone
    assert.equal(deadlineText('2026-11-15T21:00:00Z'), 'بنهاية يوم 15 نوفمبر 2026', zone)
    assert.equal(periodText('2026-11-15T21:00:00Z', '2026-11-25T21:00:00Z'), 'من 16 إلى 25 نوفمبر 2026', zone)
    assert.equal(phaseAt(CLOSES - 1, schedule(true)), 'open', zone)
  }
  delete process.env.TZ
  assert.equal(periodText('2026-11-25T21:00:00Z', '2026-12-05T21:00:00Z'), 'من 26 نوفمبر إلى 5 ديسمبر 2026')
  assert.equal(deadlineText('not a date'), null)
})

test('the clock offset aligns the device to the server from one exchange', () => {
  // The server answered 09:00:00Z; the request took 400ms on a device 90s behind.
  const sent = Date.parse('2026-11-01T08:58:29.800Z')
  assert.equal(clockOffset('2026-11-01T09:00:00Z', sent, sent + 400), 90_000)
})
