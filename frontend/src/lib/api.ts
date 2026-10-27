/**
 * Same-origin JSON client for Laravel's session (Sanctum SPA) authentication.
 *
 * The session lives in an HttpOnly cookie; nothing is kept in browser storage.
 * Before a state-changing request, /sanctum/csrf-cookie issues the XSRF-TOKEN
 * cookie, which is sent back in the X-XSRF-TOKEN header. A 419 (token expired
 * with the session) refreshes the cookie and retries once.
 *
 * Every step has a deadline (REQUEST_TIMEOUT_MS): the CSRF cookie request,
 * and each attempt of the request itself from sending it until its body
 * (success or error) has been read. A step that does not finish in time is
 * aborted and fails with a RequestTimeoutError. For a write, that means its
 * outcome is unknown: aborting the browser's request does not stop work the
 * server may already be doing, so callers that save must find out what was
 * stored rather than report a failure.
 */

export type FieldErrors = Record<string, string[]>

export class ApiError extends Error {
  readonly status: number
  readonly errors: FieldErrors
  readonly code: string | null
  /** The whole JSON answer, for errors that carry data (for example a 409 with the current state). */
  readonly payload: Record<string, unknown>

  constructor(status: number, message: string, errors: FieldErrors = {}, code: string | null = null, payload: Record<string, unknown> = {}) {
    super(message)
    this.status = status
    this.errors = errors
    this.code = code
    this.payload = payload
  }
}

/** The request never reached the server, or no readable answer came back. */
export class NetworkError extends Error {}

/**
 * No complete answer within the deadline. A NetworkError, so callers that
 * treat a lost answer as "outcome unknown" treat this the same way.
 */
export class RequestTimeoutError extends NetworkError {}

/**
 * The deadline of each step, in milliseconds. These are small JSON requests
 * and answers; 20 seconds leaves room for a slow connection or a busy server
 * without leaving a form busy indefinitely. (Tests drive it with fake timers.)
 */
export const REQUEST_TIMEOUT_MS = 20_000

/**
 * The deadline of one file upload (api.upload only): up to 50 MiB may need
 * minutes on a slow connection, so five minutes, still bounded. The CSRF
 * cookie request before it keeps the ordinary deadline.
 */
export const UPLOAD_TIMEOUT_MS = 5 * 60_000

export const messages = {
  network: 'تعذّر الاتصال بالخادم. تحقّق من اتصالك بالإنترنت ثم أعد المحاولة.',
  sessionExpired: 'انتهت صلاحية الجلسة. أعد تحميل الصفحة ثم حاول مرة أخرى.',
  unexpected: 'حدث خطأ غير متوقع. أعد المحاولة بعد قليل.',
  tooManyAttempts: 'محاولات كثيرة. انتظر قليلاً ثم أعد المحاولة.',
  timeout: 'لم يصل ردّ الخادم في الوقت المتوقع. تحقّق من اتصالك بالإنترنت ثم أعد المحاولة.',
  tooLarge: 'الطلب أكبر من الحد الذي يقبله الخادم. تأكّد من أن حجم الملف ضمن الحد المسموح ثم أعد المحاولة.',
}

/**
 * Runs one step (a fetch and the reading of its answer) with its own
 * deadline. When the deadline passes, the step is aborted and the promise
 * rejects with a RequestTimeoutError, even if the aborted work never settles.
 */
async function withDeadline<T>(step: (signal: AbortSignal) => Promise<T>, timeoutMs = REQUEST_TIMEOUT_MS): Promise<T> {
  const controller = new AbortController()
  let timer: ReturnType<typeof setTimeout> | undefined
  const deadline = new Promise<never>((_, reject) => {
    timer = setTimeout(() => {
      reject(new RequestTimeoutError(messages.timeout))
      controller.abort()
    }, timeoutMs)
  })
  try {
    return await Promise.race([step(controller.signal), deadline])
  } finally {
    clearTimeout(timer)
  }
}

function readCookie(name: string): string | null {
  const match = document.cookie.split('; ').find((cookie) => cookie.startsWith(`${name}=`))
  return match ? decodeURIComponent(match.slice(name.length + 1)) : null
}

async function refreshCsrfCookie(): Promise<void> {
  await withDeadline(async (signal) => {
    try {
      const response = await fetch('/sanctum/csrf-cookie', {
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        signal,
      })
      // Read the (empty) body too, so the step ends when the answer has.
      await response.arrayBuffer()
    } catch {
      throw new NetworkError(messages.network)
    }
  })
}

type Method = 'GET' | 'POST'

async function send(method: Method, url: string, signal: AbortSignal, body?: unknown, extraHeaders: Record<string, string> = {}): Promise<Response> {
  const headers: Record<string, string> = {
    ...extraHeaders,
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  }
  // A FormData body sets its own multipart Content-Type (with the boundary).
  if (body !== undefined && !(body instanceof FormData)) headers['Content-Type'] = 'application/json'
  const token = readCookie('XSRF-TOKEN')
  if (token) headers['X-XSRF-TOKEN'] = token

  try {
    return await fetch(url, {
      method,
      credentials: 'same-origin',
      headers,
      body: body === undefined ? undefined : body instanceof FormData ? body : JSON.stringify(body),
      signal,
    })
  } catch {
    throw new NetworkError(messages.network)
  }
}

async function toError(response: Response): Promise<ApiError> {
  let payload: { message?: string; errors?: FieldErrors; code?: string } & Record<string, unknown> = {}
  try {
    payload = await response.json()
  } catch {
    // Not JSON (for example a proxy error page): fall back to a generic message.
  }
  const fallback =
    response.status === 419
      ? messages.sessionExpired
      : response.status === 429
        ? messages.tooManyAttempts
        : response.status === 413
          ? messages.tooLarge
          : messages.unexpected
  const message = response.status >= 500 || !payload.message ? fallback : payload.message
  return new ApiError(response.status, message, payload.errors ?? {}, payload.code ?? null, payload)
}

/** The CSRF token expired with the session: refresh it and send once more. */
const CSRF_RETRY = Symbol('csrf-retry')

/** One attempt, bounded from sending until the body has been read. */
function attempt<T>(
  method: Method,
  url: string,
  body?: unknown,
  headers?: Record<string, string>,
  retryOn419 = false,
  timeoutMs = REQUEST_TIMEOUT_MS,
): Promise<T | typeof CSRF_RETRY> {
  return withDeadline(async (signal) => {
    const response = await send(method, url, signal, body, headers)
    if (response.status === 419 && retryOn419) {
      // The body is not needed; cancel it rather than leave it unread.
      await response.body?.cancel().catch(() => undefined)
      return CSRF_RETRY
    }
    if (!response.ok) throw await toError(response)
    if (response.status === 204) return undefined as T

    try {
      return (await response.json()) as T
    } catch {
      throw new NetworkError(messages.network)
    }
  }, timeoutMs)
}

async function request<T>(method: Method, url: string, body?: unknown, headers?: Record<string, string>, timeoutMs = REQUEST_TIMEOUT_MS): Promise<T> {
  if (method !== 'GET' && !readCookie('XSRF-TOKEN')) await refreshCsrfCookie()

  const first = await attempt<T>(method, url, body, headers, method !== 'GET', timeoutMs)
  if (first !== CSRF_RETRY) return first

  await refreshCsrfCookie()
  return (await attempt<T>(method, url, body, headers, false, timeoutMs)) as T
}

export const api = {
  get: <T>(url: string, headers?: Record<string, string>) => request<T>('GET', url, undefined, headers),
  post: <T>(url: string, body: unknown = {}) => request<T>('POST', url, body),
  /**
   * One file as multipart form data, with the upload deadline. Its outcome
   * after a timeout is unknown, like any write: callers reconcile.
   */
  upload: <T>(url: string, form: FormData) => request<T>('POST', url, form, undefined, UPLOAD_TIMEOUT_MS),
}

/** The message to show for any failure thrown by `api`. */
export function errorMessage(error: unknown): string {
  if (error instanceof ApiError || error instanceof NetworkError) return error.message
  return messages.unexpected
}
