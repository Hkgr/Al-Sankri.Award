import { useState } from 'react'
import type { User } from '../../../auth/useAuth'
import { StatusIcon } from '../../../components/forms/FormControls'
import type { Attachment, DocumentationLink } from './documentationApi'
import { fileUrl, formatBytes, formatName, saveAttachment, savedName } from './documentationApi'

/**
 * Stored attachments, one per row: name, optional title, format, size and
 * state, with a download (documents) or native playback (videos), and a
 * removal control only when one is given. Files come from the owner's
 * route (user and journeyKey), or from the route `urlFor` names (the
 * administration's read-only view).
 */
export function AttachmentList({
  attachments,
  user,
  journeyKey,
  urlFor,
  onRemove,
  busyId,
  emptyText,
}: {
  attachments: Attachment[]
  user?: Pick<User, 'account_context'>
  journeyKey?: string
  urlFor?: (attachment: Attachment) => string
  onRemove?: (attachment: Attachment) => void
  busyId?: number | null
  emptyText: string
}) {
  const [downloadingId, setDownloadingId] = useState<number | null>(null)
  const [downloadError, setDownloadError] = useState<{ id: number; message: string } | null>(null)

  if (attachments.length === 0) return <p className="attachment-empty">{emptyText}</p>

  const download = async (attachment: Attachment, url: string) => {
    setDownloadingId(attachment.id)
    setDownloadError(null)
    const message = await saveAttachment(url, savedName(attachment))
    setDownloadingId(null)
    if (message) setDownloadError({ id: attachment.id, message })
  }

  return (
    <ul className="attachment-list">
      {attachments.map((attachment) => {
        const url = urlFor ? urlFor(attachment) : fileUrl(user!, journeyKey!, attachment)
        const video = attachment.mime_type.startsWith('video/')
        return (
          <li key={attachment.id} className="attachment">
            <div className="attachment__main">
              <p className="attachment__name">
                <bdi>{attachment.title ?? attachment.name}</bdi>
              </p>
              <p className="attachment__meta">
                {attachment.title && (
                  <>
                    <bdi>{attachment.name}</bdi>
                    <span aria-hidden="true"> · </span>
                  </>
                )}
                {formatName(attachment.mime_type)}
                <span aria-hidden="true"> · </span>
                <bdi>{formatBytes(attachment.size_bytes)}</bdi>
              </p>
              <p className="attachment__status">
                <StatusIcon tone="success" />
                <span>محفوظ</span>
              </p>
              {video && (
                <details className="attachment__player">
                  <summary>تشغيل الفيديو</summary>
                  <video controls preload="none" src={url}>
                    <a href={url}>تنزيل الفيديو</a>
                  </video>
                </details>
              )}
            </div>
            <div className="attachment__actions">
              <button
                type="button"
                className="button button--secondary button--small"
                onClick={() => void download(attachment, url)}
                disabled={downloadingId === attachment.id}
              >
                {downloadingId === attachment.id ? 'جارٍ التنزيل…' : 'تنزيل'}
                <span className="visually-hidden"> {attachment.title ?? attachment.name}</span>
              </button>
              {downloadError?.id === attachment.id && <p className="field__error">{downloadError.message}</p>}
              {onRemove && (
                <button type="button" className="button button--quiet button--small" onClick={() => onRemove(attachment)} disabled={busyId === attachment.id}>
                  {busyId === attachment.id ? 'جارٍ الحذف…' : 'حذف'}
                  <span className="visually-hidden"> {attachment.title ?? attachment.name}</span>
                </button>
              )}
            </div>
          </li>
        )
      })}
    </ul>
  )
}

/** External links, opened in a new tab without access to this page. */
export function LinkList({ links, emptyText }: { links: DocumentationLink[]; emptyText: string }) {
  if (links.length === 0) return <p className="attachment-empty">{emptyText}</p>

  return (
    <ul className="attachment-list">
      {links.map((link, index) => (
        <li key={`${index}-${link.url}`} className="attachment">
          <div className="attachment__main">
            <p className="attachment__name">
              <bdi>{link.title}</bdi>
            </p>
            <p className="attachment__meta attachment__url">
              <a href={link.url} target="_blank" rel="noopener noreferrer external" dir="ltr">
                {link.url}
              </a>
              <span className="visually-hidden"> (يفتح في نافذة جديدة)</span>
            </p>
          </div>
        </li>
      ))}
    </ul>
  )
}
