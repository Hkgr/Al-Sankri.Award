import { useEffect, useId, useState } from 'react'
import type { User } from '../../auth/useAuth'
import { FormAlert, TextField } from '../../components/forms/FormControls'
import { useLocale } from '../../i18n/useLocale'
import { ApiError, api, errorMessage } from '../../lib/api'
import { AdminDialog } from './committees/AdminDialog'

type Preview = {
  target: { id: number; name: string; email: string; roles: string[] }
  impact: Record<string, number>
  fingerprint: string
}

export function AccountRemovalDialog({ targetId, user, onClose, onDone }: {
  targetId: number; user: User; onClose: () => void; onDone: (pendingFiles: boolean) => void
}) {
  const { t } = useLocale()
  const id = useId()
  const [preview, setPreview] = useState<Preview | null>(null)
  const [mode, setMode] = useState<'retain' | 'purge'>('retain')
  const [confirmation, setConfirmation] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [attempt, setAttempt] = useState(0)
  const [operationId, setOperationId] = useState(() => crypto.randomUUID())

  useEffect(() => {
    let active = true
    api.get<{ data: Preview }>(`/api/admin/users/${targetId}/removal-preview`)
      .then((response) => { if (active) setPreview(response.data) })
      .catch((failure) => { if (active) setError(errorMessage(failure)) })
    return () => { active = false }
  }, [targetId, attempt])

  const refresh = () => {
    setPreview(null)
    setError(null)
    setConfirmation('')
    setOperationId(crypto.randomUUID())
    setAttempt((value) => value + 1)
  }

  const remove = async () => {
    if (!preview || busy) return
    setBusy(true)
    setError(null)
    try {
      const response = await api.post<{ data: { pending_file_deletions: number } }>(`/api/admin/users/${targetId}/remove`, {
        mode, confirmation, fingerprint: preview.fingerprint, operation_id: operationId, account_context: user.account_context,
      })
      onDone(response.data.pending_file_deletions > 0)
    } catch (failure) {
      setError(errorMessage(failure))
      if (failure instanceof ApiError && failure.status === 409) {
        setPreview(null)
      }
    } finally {
      setBusy(false)
    }
  }

  return (
    <AdminDialog open title={t('removal.title')} titleId={`${id}-title`} onClose={() => { if (!busy) onClose() }} wide className="account-removal-dialog">
      <div className="admin-dialog__body admin-form">
        {error && <FormAlert tone="error"><p>{error}</p><button type="button" className="button button--secondary button--small" onClick={() => { if (preview) void remove(); else refresh() }}>{t('common.retry')}</button></FormAlert>}
        {!preview ? <p role="status">{error ? '' : t('removal.loading')}</p> : <>
          <p><strong>{t('removal.target')}: <bdi>{preview.target.name}</bdi></strong><br /><bdi dir="ltr">{preview.target.email}</bdi><br />{preview.target.roles.map((role) => t(`removal.${role}`)).join(' / ')}</p>
          <fieldset className="field field-group" disabled={busy}>
            <legend className="field__label">{t('removal.mode')}</legend>
            {(['retain', 'purge'] as const).map((value) => <label key={value} className="choice">
              <input type="radio" name={`${id}-mode`} value={value} checked={mode === value} onChange={() => setMode(value)} />
              <span><strong>{t(`removal.${value}`)}</strong><br />{t(`removal.${value}Hint`)}</span>
            </label>)}
          </fieldset>
          <section aria-labelledby={`${id}-impact`}>
            <h3 id={`${id}-impact`}>{t('removal.impact')}</h3>
            <p>{t(mode === 'retain' ? 'removal.keepCounts' : 'removal.deleteCounts')}</p>
            <dl className="admin-facts">
              {Object.entries(preview.impact).map(([key, value]) => <div key={key}><dt>{t(`removal.count.${key}`)}</dt><dd>{value}</dd></div>)}
            </dl>
          </section>
          {(preview.impact.committees > 0 || preview.impact.results > 0) && <FormAlert tone="info"><p>{t('removal.reconstitution')}</p></FormAlert>}
          <p>{t('removal.emailHint')}</p>
          {mode === 'purge' && <TextField id={`${id}-confirmation`} label={t('removal.confirmEmail')} value={confirmation} onChange={setConfirmation} autoComplete="off" required maxLength={255} />}
        </>}
      </div>
      <div className="admin-dialog__actions">
        <button type="button" className="button button--secondary" onClick={onClose} disabled={busy}>{t('تراجع')}</button>
        <button type="button" className="button button--primary" onClick={() => void remove()} disabled={busy || !preview || (mode === 'purge' && confirmation !== preview.target.email)} aria-busy={busy || undefined}>
          {busy ? t('جارٍ التنفيذ…') : t(`removal.${mode}`)}
        </button>
      </div>
    </AdminDialog>
  )
}
