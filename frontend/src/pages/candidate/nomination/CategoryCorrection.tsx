import { useState } from 'react'
import { api, errorMessage } from '../../../lib/api'
import { FormAlert } from '../../../components/forms/FormControls'
import { useLocale } from '../../../i18n/useLocale'

/** A category-only correction for flagged legacy applications. */
export function CategoryCorrection({ options, url, context, journeyKey, admin = false, onSaved }: {
  options: { code: string; name: string }[]
  url: string
  context: string
  journeyKey?: string
  admin?: boolean
  onSaved: () => Promise<void>
}) {
  const { t } = useLocale()
  const [category, setCategory] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const save = async () => {
    setBusy(true)
    setError(null)
    try {
      await api.post(url, { subcategory: category, account_context: context, journey_key: journeyKey })
      await onSaved()
    } catch (failure) {
      setError(errorMessage(failure))
    } finally {
      setBusy(false)
    }
  }
  return <FormAlert tone="info">
    <p>{t(admin ? 'category.adminReview' : 'category.review')}</p>
    <label className="field__label" htmlFor="category-correction">{t('category.choose')}</label>
    <select className="field__input" id="category-correction" value={category} onChange={(event) => setCategory(event.target.value)} disabled={busy}>
      <option value="">{t('category.choose')}</option>
      {options.map((option) => <option key={option.code} value={option.code}>{option.name}</option>)}
    </select>
    {error && <p role="alert">{error}</p>}
    <p><button className="button button--primary" disabled={!category || busy} onClick={() => void save()}>{t('category.save')}</button></p>
  </FormAlert>
}
