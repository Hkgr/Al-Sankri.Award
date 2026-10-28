import { useEffect, useId, useRef, useState } from 'react'
import type { User } from '../../auth/useAuth'
import { api, errorMessage } from '../../lib/api'
import { contextHeader } from '../candidate/candidateApi'
import { saveFile } from './saveFile'

export type ExportFormat = 'xlsx' | 'pdf'

/**
 * «تصدير»: Excel (.xlsx) or PDF of the table as it is filtered, searched,
 * sorted and scoped right now (the address is read when the choice is
 * made). The server builds the file with every matching row, or refuses
 * above its limit with a message shown here. Only for accounts holding
 * reports.export; the server checks the table's own permission too.
 */
export function ExportMenu({ user, url, label = 'تصدير' }: { user: User; url: (format: ExportFormat) => string; label?: string }) {
  const [open, setOpen] = useState(false)
  const [busy, setBusy] = useState<ExportFormat | null>(null)
  const [error, setError] = useState<string | null>(null)
  const menuId = useId()
  const container = useRef<HTMLDivElement>(null)
  const button = useRef<HTMLButtonElement>(null)

  useEffect(() => {
    if (!open) return
    const onKey = (event: KeyboardEvent) => {
      if (event.key !== 'Escape') return
      setOpen(false)
      button.current?.focus()
    }
    const onPointer = (event: PointerEvent) => {
      if (!container.current?.contains(event.target as Node)) setOpen(false)
    }
    document.addEventListener('keydown', onKey)
    document.addEventListener('pointerdown', onPointer)
    return () => {
      document.removeEventListener('keydown', onKey)
      document.removeEventListener('pointerdown', onPointer)
    }
  }, [open])

  if (!user.admin_permissions.includes('reports.export')) return null

  const run = async (format: ExportFormat) => {
    setOpen(false)
    setBusy(format)
    setError(null)
    try {
      const file = await api.download(url(format), contextHeader(user))
      saveFile(file.blob, file.filename)
    } catch (failure) {
      setError(errorMessage(failure))
    } finally {
      setBusy(null)
      button.current?.focus()
    }
  }

  return (
    <div className="admin-export" ref={container}>
      <button
        ref={button}
        type="button"
        className="button button--secondary button--small"
        aria-expanded={open}
        aria-controls={menuId}
        aria-busy={busy !== null || undefined}
        disabled={busy !== null}
        onClick={() => setOpen((shown) => !shown)}
      >
        {busy ? 'جارٍ إعداد الملف…' : label}
      </button>
      <ul id={menuId} className="admin-export__menu" hidden={!open}>
        <li>
          <button type="button" onClick={() => void run('xlsx')}>
            Excel (.xlsx)
          </button>
        </li>
        <li>
          <button type="button" onClick={() => void run('pdf')}>
            PDF
          </button>
        </li>
      </ul>
      {error && (
        <p className="admin-export__error" role="alert">
          {error}
        </p>
      )}
    </div>
  )
}
