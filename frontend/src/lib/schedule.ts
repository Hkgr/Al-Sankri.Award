/**
 * The award's public schedule (GET /api/public/schedule) and the countdown
 * arithmetic, kept free of imports so the Node test runner can load it.
 *
 * Every instant is an absolute timestamp (ISO 8601 with Z from the server),
 * compared as milliseconds; dates are shown in Asia/Damascus through Intl,
 * so the visitor's own timezone never moves a deadline. The server decides
 * whether applications are open; the countdown only counts down to the
 * instant the server announced.
 */

export const AWARD_TIME_ZONE = 'Asia/Damascus'

export type PublicSchedule = {
  server_time: string
  cycle: { name: string; year: number; timezone: string } | null
  application: { opens_at: string | null; closes_at: string | null; open: boolean } | null
  judging: { opens_at: string | null; closes_at: string | null } | null
}

/**
 * - `upcoming`: applications not open yet (the server says closed, and an opening instant lies ahead);
 * - `open`: applications open, counting down to the closing instant;
 * - `unavailable`: the server refuses applications, without an elapsed deadline;
 * - `closed`: the application deadline has elapsed, without current judging;
 * - `judging`: applications ended and the announced judging interval is current;
 * - `judged`: the judging period is over (no claim about results);
 * - `unknown`: no schedule published.
 */
export type ApplicationPhase = 'upcoming' | 'open' | 'unavailable' | 'closed' | 'unknown'
export type JudgingPhase = 'upcoming' | 'current' | 'ended' | 'unknown'
export type SchedulePhase = ApplicationPhase | 'judging' | 'judged'

export type Remaining = { days: number; hours: number; minutes: number; seconds: number; totalMs: number }

const MONTHS = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر']

function instant(value: string | null | undefined): number | null {
  if (!value) return null
  const ms = Date.parse(value)
  return Number.isNaN(ms) ? null : ms
}

/**
 * Application availability at `now` (milliseconds aligned to the server clock).
 * An `open` answer from the server ends at its closing instant without
 * waiting for a reload; a closed one is never reopened by the client.
 */
export function applicationPhaseAt(now: number, schedule: PublicSchedule | null): ApplicationPhase {
  const application = schedule?.application
  if (!application) return 'unknown'
  const opens = instant(application.opens_at)
  const closes = instant(application.closes_at)

  if (closes !== null && now >= closes) return 'closed'
  if (application.open) return 'open'
  if (opens !== null && now < opens) return 'upcoming'

  return 'unavailable'
}

/** Judging is current only inside a complete, valid announced interval. */
export function judgingPhaseAt(now: number, schedule: PublicSchedule | null): JudgingPhase {
  const opens = instant(schedule?.judging?.opens_at)
  const closes = instant(schedule?.judging?.closes_at)
  if (opens === null || closes === null || closes <= opens) return 'unknown'
  if (now < opens) return 'upcoming'
  if (now >= closes) return 'ended'

  return 'current'
}

/** Availability never implies that an application or judging period has elapsed. */
export function phaseAt(now: number, schedule: PublicSchedule | null): SchedulePhase {
  const application = applicationPhaseAt(now, schedule)
  if (application !== 'closed') return application
  const judging = judgingPhaseAt(now, schedule)
  if (judging === 'current') return 'judging'
  if (judging === 'ended') return 'judged'

  return 'closed'
}

/** The instant the countdown runs to in this phase, or null when none is shown. */
export function countdownTarget(phase: SchedulePhase, schedule: PublicSchedule | null): number | null {
  if (phase === 'open') return instant(schedule?.application?.closes_at)
  if (phase === 'upcoming') return instant(schedule?.application?.opens_at)

  return null
}

/** Whole days, hours, minutes and seconds left until `target`, never below zero. */
export function remaining(now: number, target: number): Remaining {
  const totalMs = Math.max(0, target - now)
  const totalSeconds = Math.floor(totalMs / 1000)

  return {
    days: Math.floor(totalSeconds / 86_400),
    hours: Math.floor((totalSeconds % 86_400) / 3_600),
    minutes: Math.floor((totalSeconds % 3_600) / 60),
    seconds: totalSeconds % 60,
    totalMs,
  }
}

/** The calendar date of an instant in Damascus: day, month (1–12) and year. */
export function damascusDate(ms: number): { day: number; month: number; year: number } {
  const parts = new Intl.DateTimeFormat('en-US', { timeZone: AWARD_TIME_ZONE, year: 'numeric', month: 'numeric', day: 'numeric' }).formatToParts(new Date(ms))
  const part = (type: string) => Number(parts.find((item) => item.type === type)?.value)

  return { day: part('day'), month: part('month'), year: part('year') }
}

/**
 * The last day an exclusive end instant still includes (one millisecond
 * before it), e.g. 2026-11-16T00:00+03:00 → 15 November.
 */
export function lastIncludedDay(endMs: number): { day: number; month: number; year: number } {
  return damascusDate(endMs - 1)
}

export function monthName(month: number): string {
  return MONTHS[month - 1] ?? ''
}

/** «بنهاية يوم 15 نوفمبر 2026» for an exclusive closing instant. */
export function deadlineText(closesAt: string): string | null {
  const ms = instant(closesAt)
  if (ms === null) return null
  const { day, month, year } = lastIncludedDay(ms)

  return `بنهاية يوم ${day} ${monthName(month)} ${year}`
}

/** «من 16 إلى 25 نوفمبر 2026» for a period with an exclusive end. */
export function periodText(opensAt: string | null, closesAt: string | null): string | null {
  const start = instant(opensAt)
  const end = instant(closesAt)
  if (start === null || end === null) return null
  const from = damascusDate(start)
  const to = lastIncludedDay(end)

  if (from.year !== to.year) return `من ${from.day} ${monthName(from.month)} ${from.year} إلى ${to.day} ${monthName(to.month)} ${to.year}`
  if (from.month !== to.month) return `من ${from.day} ${monthName(from.month)} إلى ${to.day} ${monthName(to.month)} ${to.year}`

  return `من ${from.day} إلى ${to.day} ${monthName(to.month)} ${to.year}`
}

/** The difference between the server's clock and this device's, from one exchange. */
export function clockOffset(serverTime: string, sentAt: number, receivedAt: number): number {
  const server = instant(serverTime)
  if (server === null) return 0

  return server - (sentAt + receivedAt) / 2
}
