import type { User } from '../../../auth/useAuth'

/**
 * Types, labels and limits for Stage 3 («ملفات العمل وتوثيقه») and the
 * final submission (backend: /api/candidate/documentation, /review,
 * /submission). The server checks every file again (type, structure and
 * exact size); the checks here only spare a pointless upload.
 */

export type AttachmentKind = 'explanatory' | 'supporting' | 'video'

export type Attachment = {
  id: number
  kind: AttachmentKind
  title: string | null
  name: string
  mime_type: string
  size_bytes: number
  scan_status: 'pending' | 'clean' | 'infected' | 'error'
  operation_id: string | null
  uploaded_at: string | null
}

export type DocumentationLink = { title: string; url: string }

/** What award administration has done with a submission (never its internal notes). */
export type Processing = 'pending' | 'referred' | 'excluded'

export const PROCESSING_TEXT: Record<Processing, string> = {
  pending: 'ملف الترشح لدى إدارة الجائزة للمراجعة الأولية.',
  referred: 'أحالت إدارة الجائزة ملف الترشح إلى لجنة التحكيم.',
  excluded: 'لم يُحل ملف الترشح إلى التحكيم بعد المراجعة الأولية لإدارة الجائزة، وحُفظ في الأرشيف.',
}

export type Receipt = { reference_number: string; submitted_at: string; version_number: number; processing: Processing }

export type Documentation = {
  revision: number
  confirmed: boolean
  confirmed_at: string | null
  editable: boolean
  attachments: Attachment[]
  links: DocumentationLink[]
  submission?: Receipt | null
}

export type Limits = {
  document_max_bytes: number
  video_max_bytes: number
  max_supporting: number
  max_videos: number
  max_links: number
}

export type DocumentationState = {
  data: Documentation | null
  availability?: { status: 'nomination_details_required' }
  journey_key?: string
  limits?: Limits
}

/** 25 MiB and 50 MiB, as the server enforces them. */
export const DOCUMENT_MAX_BYTES = 26_214_400
export const VIDEO_MAX_BYTES = 52_428_800

export const KIND_RULES: Record<AttachmentKind, { extensions: string[]; accept: string; maxBytes: number; formats: string }> = {
  explanatory: {
    extensions: ['pdf', 'doc', 'docx'],
    accept: '.pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    maxBytes: DOCUMENT_MAX_BYTES,
    formats: 'PDF أو DOC أو DOCX',
  },
  supporting: {
    extensions: ['pdf', 'doc', 'docx'],
    accept: '.pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    maxBytes: DOCUMENT_MAX_BYTES,
    formats: 'PDF أو DOC أو DOCX',
  },
  video: { extensions: ['mp4', 'webm'], accept: '.mp4,.webm,video/mp4,video/webm', maxBytes: VIDEO_MAX_BYTES, formats: 'MP4 أو WebM' },
}

const FORMAT_NAMES: Record<string, string> = {
  'application/pdf': 'PDF',
  'application/msword': 'Word (DOC)',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document': 'Word (DOCX)',
  'video/mp4': 'فيديو MP4',
  'video/webm': 'فيديو WebM',
}

export function formatName(mimeType: string): string {
  return FORMAT_NAMES[mimeType] ?? mimeType
}

/** Sizes in binary units, as the limits are stated (25 MiB = 26,214,400 bytes). */
export function formatBytes(bytes: number): string {
  if (bytes >= 1_048_576) return `${(bytes / 1_048_576).toLocaleString('en', { maximumFractionDigits: 1 })} ميغابايت`
  if (bytes >= 1024) return `${Math.max(1, Math.round(bytes / 1024))} كيلوبايت`
  return `${bytes} بايت`
}

/**
 * A file chosen in the browser that the server would certainly refuse:
 * the reason, or null. (Content checks happen on the server only.)
 */
export function precheck(kind: AttachmentKind, file: File): string | null {
  const rules = KIND_RULES[kind]
  const extension = file.name.includes('.') ? file.name.split('.').pop()!.toLowerCase() : ''
  if (!rules.extensions.includes(extension)) return `نوع الملف غير مقبول هنا. الأنواع المقبولة: ${rules.formats}.`
  if (file.size === 0) return 'الملف فارغ.'
  if (file.size > rules.maxBytes) return `حجم الملف (${formatBytes(file.size)}) أكبر من الحد المسموح (${formatBytes(rules.maxBytes)}).`
  return null
}

/**
 * The owner's file. A link or a <video> cannot send headers, so the
 * account context and journey key travel in the query; the server refuses
 * them for any other account or journey.
 */
export function fileUrl(user: Pick<User, 'account_context'>, journeyKey: string, attachment: Attachment): string {
  const query = new URLSearchParams({ account_context: user.account_context, journey_key: journeyKey })
  return `/api/candidate/documentation/files/${attachment.id}?${query.toString()}`
}

/** The name saved on disk: a video keeps an extension the player recognises. */
export function savedName(attachment: Pick<Attachment, 'name' | 'mime_type'>): string {
  if (!attachment.mime_type.startsWith('video/')) return attachment.name
  if (/\.(mp4|webm)$/i.test(attachment.name)) return attachment.name
  return `${attachment.name}${attachment.mime_type === 'video/webm' ? '.webm' : '.mp4'}`
}

const DOWNLOAD_TIMEOUT_MS = 5 * 60_000

/**
 * Saves the file the address names. The request is the same kind the page
 * uses (session cookie and Origin), and a JSON error is shown instead of
 * being saved as a file. `download=1` asks the server for an attachment.
 */
export async function saveAttachment(url: string, name: string): Promise<string | null> {
  const controller = new AbortController()
  const timer = setTimeout(() => controller.abort(), DOWNLOAD_TIMEOUT_MS)
  const address = `${url}${url.includes('?') ? '&' : '?'}download=1`
  try {
    const response = await fetch(address, {
      credentials: 'same-origin',
      headers: { Accept: '*/*', 'X-Requested-With': 'XMLHttpRequest' },
      signal: controller.signal,
    })
    const type = response.headers.get('Content-Type') ?? ''
    if (!response.ok || type.includes('json')) {
      let message = 'تعذّر تنزيل الملف.'
      try {
        const payload = (await response.json()) as { message?: string }
        if (payload.message) message = payload.message
      } catch {
        // The body was not an error document.
      }
      return message
    }
    const blob = await response.blob()
    const objectUrl = URL.createObjectURL(blob)
    const anchor = document.createElement('a')
    anchor.href = objectUrl
    anchor.download = name
    document.body.appendChild(anchor)
    anchor.click()
    anchor.remove()
    setTimeout(() => URL.revokeObjectURL(objectUrl), 0)
    return null
  } catch {
    return 'تعذّر تنزيل الملف. تحقق من الاتصال ثم أعد المحاولة.'
  } finally {
    clearTimeout(timer)
  }
}

/**
 * The state of one journey only, for reconciling an upload or a submission
 * whose answer was lost: the server resolves the journey the key names,
 * even after its cycle closed, and never substitutes another cycle's. Send
 * it with contextHeader(user), and accept the answer only when its
 * journey_key is still the same.
 */
export function journeyStateUrl(area: 'documentation' | 'review', journeyKey: string): string {
  return `/api/candidate/${area}/journey?${new URLSearchParams({ journey_key: journeyKey }).toString()}`
}

export const SECTION_TITLES: Record<AttachmentKind, string> = {
  explanatory: 'وثيقة شرح العمل',
  supporting: 'وثائق داعمة إضافية',
  video: 'فيديوهات توثيق العمل',
}

export const SUBMIT_CONFIRMATION = 'يُرسل ملف الترشح إلى إدارة الجائزة للمراجعة الأولية. بعد الإرسال لن تتمكن من إضافة ملفات أو تعديل بيانات المشاركة. هل تريد تأكيد الإرسال؟'
