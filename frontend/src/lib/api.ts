/**
 * Same-origin JSON client for Laravel's session (Sanctum SPA) authentication.
 *
 * The session lives in an HttpOnly cookie; nothing is kept in browser storage.
 * Before a state-changing request, /sanctum/csrf-cookie issues the XSRF-TOKEN
 * cookie, which is sent back in the X-XSRF-TOKEN header. A 419 (token expired
 * with the session) refreshes the cookie and retries once.
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

export const messages = {
  network: 'تعذّر الاتصال بالخادم. تحقّق من اتصالك بالإنترنت ثم أعد المحاولة.',
  sessionExpired: 'انتهت صلاحية الجلسة. أعد تحميل الصفحة ثم حاول مرة أخرى.',
  unexpected: 'حدث خطأ غير متوقع. أعد المحاولة بعد قليل.',
  tooManyAttempts: 'محاولات كثيرة. انتظر قليلاً ثم أعد المحاولة.',
}

function readCookie(name: string): string | null {
  const match = document.cookie.split('; ').find((cookie) => cookie.startsWith(`${name}=`))
  return match ? decodeURIComponent(match.slice(name.length + 1)) : null
}

async function refreshCsrfCookie(): Promise<void> {
  try {
    await fetch('/sanctum/csrf-cookie', {
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    })
  } catch {
    throw new NetworkError(messages.network)
  }
}

type Method = 'GET' | 'POST'

async function send(method: Method, url: string, body?: unknown, extraHeaders: Record<string, string> = {}): Promise<Response> {
  const headers: Record<string, string> = {
    ...extraHeaders,
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  }
  if (body !== undefined) headers['Content-Type'] = 'application/json'
  const token = readCookie('XSRF-TOKEN')
  if (token) headers['X-XSRF-TOKEN'] = token

  try {
    return await fetch(url, {
      method,
      credentials: 'same-origin',
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
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
    response.status === 419 ? messages.sessionExpired : response.status === 429 ? messages.tooManyAttempts : messages.unexpected
  const message = response.status >= 500 || !payload.message ? fallback : payload.message
  return new ApiError(response.status, message, payload.errors ?? {}, payload.code ?? null, payload)
}

async function request<T>(method: Method, url: string, body?: unknown, headers?: Record<string, string>): Promise<T> {
  if (method !== 'GET' && !readCookie('XSRF-TOKEN')) await refreshCsrfCookie()

  let response = await send(method, url, body, headers)
  if (response.status === 419 && method !== 'GET') {
    await refreshCsrfCookie()
    response = await send(method, url, body, headers)
  }
  if (!response.ok) throw await toError(response)
  if (response.status === 204) return undefined as T

  try {
    return (await response.json()) as T
  } catch {
    throw new NetworkError(messages.network)
  }
}

export const api = {
  get: <T>(url: string, headers?: Record<string, string>) => request<T>('GET', url, undefined, headers),
  post: <T>(url: string, body: unknown = {}) => request<T>('POST', url, body),
}

/** The message to show for any failure thrown by `api`. */
export function errorMessage(error: unknown): string {
  if (error instanceof ApiError || error instanceof NetworkError) return error.message
  return messages.unexpected
}
