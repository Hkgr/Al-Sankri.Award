import { useEffect, useRef } from 'react'
import type { ReactNode } from 'react'

/**
 * A modal built on the native <dialog>: focus stays inside while it is
 * open, Escape closes it, and focus returns to the control that opened it.
 */
export function AdminDialog({
  open,
  title,
  titleId,
  onClose,
  children,
  wide,
}: {
  open: boolean
  title: string
  titleId: string
  onClose: () => void
  children: ReactNode
  wide?: boolean
}) {
  const ref = useRef<HTMLDialogElement>(null)
  const opener = useRef<Element | null>(null)

  useEffect(() => {
    const dialog = ref.current
    if (!dialog) return
    if (open && !dialog.open) {
      opener.current = document.activeElement
      dialog.showModal()
    } else if (!open && dialog.open) {
      dialog.close()
      if (opener.current instanceof HTMLElement) opener.current.focus()
    }
  }, [open])

  return (
    <dialog
      ref={ref}
      className={wide ? 'admin-dialog admin-dialog--wide' : 'admin-dialog'}
      aria-labelledby={titleId}
      onCancel={(event) => {
        event.preventDefault()
        onClose()
      }}
    >
      <div className="admin-dialog__header">
        <h2 id={titleId} className="admin-dialog__title">
          {title}
        </h2>
        <button type="button" className="admin-dialog__close" onClick={onClose}>
          <span aria-hidden="true">×</span>
          <span className="visually-hidden">إغلاق</span>
        </button>
      </div>
      {open && children}
    </dialog>
  )
}

/** A yes/no question before a change that is not undone by itself. */
export function ConfirmDialog({
  open,
  title,
  text,
  confirmLabel,
  busy,
  onConfirm,
  onClose,
}: {
  open: boolean
  title: string
  text: ReactNode
  confirmLabel: string
  busy: boolean
  onConfirm: () => void
  onClose: () => void
}) {
  return (
    <AdminDialog open={open} title={title} titleId="admin-confirm-title" onClose={onClose}>
      <div className="admin-dialog__body">{text}</div>
      <div className="admin-dialog__actions">
        <button type="button" className="button button--secondary" onClick={onClose} autoFocus>
          تراجع
        </button>
        <button type="button" className="button button--primary" onClick={onConfirm} disabled={busy} aria-busy={busy || undefined}>
          {busy ? 'جارٍ التنفيذ…' : confirmLabel}
        </button>
      </div>
    </AdminDialog>
  )
}
