import { useEffect, useRef } from 'react'
import type { ReactNode } from 'react'
import { describedBy } from '../../../components/forms/describedBy'
import { FieldMessages, StatusIcon, TextField } from '../../../components/forms/FormControls'
import { MONTHS } from '../basic/formModel'
import type { Errors } from '../basic/formModel'
import { SelectField, TextAreaField } from '../basic/fields'
import { artworkFor, artworkLabel } from './nominationApi'
import type { DomainOption, Governorate } from './nominationApi'
import { LIMITS, MAX_LOCATIONS, MAX_PARTNERS } from './nominationModel'
import type { DateParts, LocationRow, PartnerRow } from './nominationModel'

/*
 * Stage 2 controls, built on the Stage 1 field styles: visible labels,
 * «(مطلوب)» / «(اختياري)», hints and errors linked with aria-describedby,
 * fieldsets with legends for grouped choices.
 */

/** The award artwork of a domain: the original SVG, never redrawn or recoloured. */
export function DomainArtwork({ artwork, domainName, size }: { artwork: string; domainName: string; size: 'card' | 'preview' }) {
  const src = artworkFor(artwork)
  if (!src) return null
  return <img className={`domain-artwork domain-artwork--${size}`} src={src} alt={artworkLabel(domainName)} />
}

/**
 * A question asked inline before discarding input (instead of a modal
 * dialog): what will be lost, a button to go ahead, a button to keep it.
 */
export function DiscardWarning({ children, confirmLabel, keepLabel, onConfirm, onKeep }: { children: ReactNode; confirmLabel: string; keepLabel: string; onConfirm: () => void; onKeep: () => void }) {
  return (
    <div className="form-alert form-alert--error type-change" role="alert">
      <StatusIcon tone="error" />
      <div className="form-alert__body">
        <p>{children}</p>
        <div className="candidate-actions">
          <button type="button" className="button button--primary" onClick={onConfirm}>
            {confirmLabel}
          </button>
          <button type="button" className="button button--secondary" onClick={onKeep}>
            {keepLabel}
          </button>
        </div>
      </div>
    </div>
  )
}

/**
 * The main domain as radio cards (each with its award artwork), then the
 * chosen domain's subcategories only, and a preview of the choice.
 */
export function DomainChooser({
  domains,
  domain,
  subcategory,
  onDomain,
  onSubcategory,
  errors,
  warning,
}: {
  domains: DomainOption[]
  domain: string
  subcategory: string
  onDomain: (code: string) => void
  onSubcategory: (code: string) => void
  errors: Errors
  warning: ReactNode
}) {
  const selected = domains.find((option) => option.code === domain)
  const chosenSubcategory = selected?.subcategories.find((option) => option.code === subcategory)

  return (
    <div className="domain-step">
      <fieldset className="choice-cards" aria-describedby={errors.domain ? 'domain-error' : undefined}>
        <legend className="field__label">
          محور الترشح<span className="field__required"> (مطلوب)</span>
        </legend>
        <div className="choice-cards__grid domain-cards">
          {domains.map((option, index) => (
            <label key={option.code} className="choice-card domain-card" data-checked={domain === option.code}>
              <input
                id={`domain-option-${index}`}
                type="radio"
                name="domain"
                value={option.code}
                checked={domain === option.code}
                onChange={() => onDomain(option.code)}
                aria-invalid={errors.domain ? true : undefined}
              />
              <span className="choice-card__title">{option.name}</span>
              <span className="domain-card__art" aria-hidden="true">
                <DomainArtwork artwork={option.artwork} domainName={option.name} size="card" />
              </span>
            </label>
          ))}
        </div>
        <FieldMessages id="domain" error={errors.domain} />
      </fieldset>

      {warning}

      {selected && (
        <div className="domain-choice">
          <div className="domain-preview" aria-live="polite">
            <DomainArtwork artwork={selected.artwork} domainName={selected.name} size="preview" />
            <div className="domain-preview__text">
              <p className="domain-preview__name">{selected.name}</p>
              <p className="domain-preview__sub">{chosenSubcategory ? chosenSubcategory.name : 'اختر فئة الترشح ضمن هذا المحور.'}</p>
            </div>
          </div>
          <fieldset className="field field-group" aria-describedby={errors.subcategory ? 'subcategory-error' : undefined}>
            <legend className="field__label">
              فئة الترشح ضمن «{selected.name}»<span className="field__required"> (مطلوب)</span>
            </legend>
            <div className="radio-row">
              {selected.subcategories.map((option, index) => (
                <label key={option.code} className="choice">
                  <input
                    id={`subcategory-option-${index}`}
                    type="radio"
                    name="subcategory"
                    value={option.code}
                    checked={subcategory === option.code}
                    onChange={() => onSubcategory(option.code)}
                    aria-invalid={errors.subcategory ? true : undefined}
                  />
                  <span>{option.name}</span>
                </label>
              ))}
            </div>
            <FieldMessages id="subcategory" error={errors.subcategory} />
          </fieldset>
        </div>
      )}
    </div>
  )
}

/** Day, month and year as three labelled controls. */
export function DatePartsField({
  idBase,
  label,
  hint,
  value,
  onChange,
  error,
}: {
  idBase: string
  label: string
  hint: string
  value: DateParts
  onChange: (value: DateParts) => void
  error?: string
}) {
  const hintId = `${idBase}-hint`
  const described = describedBy(hintId, error && `${idBase}-error`)

  return (
    <fieldset className="field field-group">
      <legend className="field__label">
        {label}
        <span className="field__required"> (مطلوب)</span>
      </legend>
      <p id={hintId} className="field__hint">
        {hint}
      </p>
      <div className="date-field">
        <div className="field">
          <label className="field__sublabel" htmlFor={`${idBase}-day`}>
            اليوم
          </label>
          <input
            id={`${idBase}-day`}
            className="field__input"
            inputMode="numeric"
            dir="ltr"
            maxLength={2}
            autoComplete="off"
            value={value.day}
            onChange={(event) => onChange({ ...value, day: event.target.value })}
            aria-invalid={error ? true : undefined}
            aria-describedby={described}
          />
        </div>
        <div className="field">
          <label className="field__sublabel" htmlFor={`${idBase}-month`}>
            الشهر
          </label>
          <select
            id={`${idBase}-month`}
            className="field__input field__select"
            value={value.month}
            onChange={(event) => onChange({ ...value, month: event.target.value })}
            aria-invalid={error ? true : undefined}
            aria-describedby={described}
          >
            <option value="">اختر الشهر</option>
            {MONTHS.map((name, index) => (
              <option key={name} value={String(index + 1)}>
                {`${name} (${index + 1})`}
              </option>
            ))}
          </select>
        </div>
        <div className="field">
          <label className="field__sublabel" htmlFor={`${idBase}-year`}>
            السنة
          </label>
          <input
            id={`${idBase}-year`}
            className="field__input"
            inputMode="numeric"
            dir="ltr"
            maxLength={4}
            autoComplete="off"
            value={value.year}
            onChange={(event) => onChange({ ...value, year: event.target.value })}
            aria-invalid={error ? true : undefined}
            aria-describedby={described}
          />
        </div>
      </div>
      <FieldMessages id={idBase} error={error} />
    </fieldset>
  )
}

/** Moves focus to an element by id once the list has re-rendered. */
function useFocusAfterChange(dependency: unknown) {
  const target = useRef<string | null>(null)
  useEffect(() => {
    if (!target.current) return
    document.getElementById(target.current)?.focus()
    target.current = null
  }, [dependency])
  return target
}

/** One or more places of implementation in the cycle's country. */
export function LocationRows({
  rows,
  onChange,
  governorates,
  countryName,
  errors,
  newRow,
}: {
  rows: LocationRow[]
  onChange: (rows: LocationRow[]) => void
  governorates: Governorate[]
  countryName: string
  errors: Errors
  newRow: () => LocationRow
}) {
  const focusTargetRef = useFocusAfterChange(rows)
  const update = (index: number, part: 'governorate' | 'locality' | 'address', value: string) =>
    onChange(rows.map((row, position) => (position === index ? { ...row, [part]: value } : row)))

  return (
    <fieldset className="field field-group field-group--full">
      <legend className="field__label">
        أماكن التنفيذ<span className="field__required"> (مطلوب)</span>
      </legend>
      <p className="field__hint">
        البلد: <strong>{countryName}</strong>. أضف كل مكان نُفّذ فيه العمل؛ مكان واحد على الأقل، وحتى {MAX_LOCATIONS} مكاناً.
      </p>
      <ol className="link-rows">
        {rows.map((row, index) => (
          <li key={row.key} className="repeat-card">
            <p className="repeat-card__title">مكان التنفيذ {index + 1}</p>
            <div className="repeat-card__grid">
              <SelectField
                id={`locations-${index}-governorate`}
                label="المحافظة"
                value={row.governorate}
                onChange={(value) => update(index, 'governorate', value)}
                options={governorates.map((option) => ({ value: option.code, label: option.name }))}
                placeholder="اختر المحافظة"
                error={errors[`locations.${index}.governorate`]}
              />
              <TextField
                id={`locations-${index}-locality`}
                label="المدينة أو البلدة أو القرية"
                value={row.locality}
                onChange={(value) => update(index, 'locality', value)}
                error={errors[`locations.${index}.locality`]}
                autoComplete="off"
                dir="auto"
                required
              />
              <div className="form-grid__full">
                <TextField
                  id={`locations-${index}-address`}
                  label="العنوان أو تفاصيل المكان"
                  value={row.address}
                  onChange={(value) => update(index, 'address', value)}
                  error={errors[`locations.${index}.address`]}
                  hint="مثلاً: الحي أو اسم المركز أو المدرسة."
                  autoComplete="off"
                  dir="auto"
                  optional
                />
              </div>
            </div>
            {rows.length > 1 && (
              <button
                type="button"
                className="button button--secondary link-row__remove"
                onClick={() => {
                  focusTargetRef.current = 'add-location'
                  onChange(rows.filter((_, position) => position !== index))
                }}
              >
                حذف<span className="visually-hidden"> مكان التنفيذ {index + 1}</span>
              </button>
            )}
          </li>
        ))}
      </ol>
      {errors.locations && <FieldMessages id="locations" error={errors.locations} />}
      {rows.length < MAX_LOCATIONS ? (
        <button
          id="add-location"
          type="button"
          className="button button--secondary link-rows__add"
          onClick={() => {
            focusTargetRef.current = `locations-${rows.length}-governorate`
            onChange([...rows, newRow()])
          }}
        >
          إضافة مكان تنفيذ
        </button>
      ) : (
        <p id="add-location" className="field__hint" tabIndex={-1}>
          وصلت إلى الحد الأقصى ({MAX_LOCATIONS} مكاناً).
        </p>
      )}
    </fieldset>
  )
}

/** Named partners: a name and, optionally, the nature of the partnership. */
export function PartnerRows({ rows, onChange, errors, newRow }: { rows: PartnerRow[]; onChange: (rows: PartnerRow[]) => void; errors: Errors; newRow: () => PartnerRow }) {
  const focusTargetRef = useFocusAfterChange(rows)
  const update = (index: number, part: 'name' | 'contribution', value: string) =>
    onChange(rows.map((row, position) => (position === index ? { ...row, [part]: value } : row)))

  return (
    <div className="field-group--full partner-rows">
      <ol className="link-rows">
        {rows.map((row, index) => (
          <li key={row.key} className="repeat-card">
            <p className="repeat-card__title">الشريك {index + 1}</p>
            <TextField
              id={`partnerships-${index}-name`}
              label="اسم الجهة الشريكة"
              value={row.name}
              onChange={(value) => update(index, 'name', value)}
              error={errors[`partnerships.${index}.name`]}
              autoComplete="off"
              dir="auto"
              required
            />
            <TextAreaField
              id={`partnerships-${index}-contribution`}
              label="طبيعة الشراكة أو المساهمة"
              value={row.contribution}
              onChange={(value) => update(index, 'contribution', value)}
              error={errors[`partnerships.${index}.contribution`]}
              hint="مثلاً: تقديم مكان التدريب، أو التنفيذ المشترك."
              limit={LIMITS.contribution}
              autoComplete="off"
              optional
            />
            <button
              type="button"
              className="button button--secondary link-row__remove"
              onClick={() => {
                focusTargetRef.current = 'add-partner'
                onChange(rows.filter((_, position) => position !== index))
              }}
            >
              حذف<span className="visually-hidden"> الشريك {index + 1}</span>
            </button>
          </li>
        ))}
      </ol>
      {errors.partnerships && <FieldMessages id="partnerships" error={errors.partnerships} />}
      {rows.length < MAX_PARTNERS ? (
        <button
          id="add-partner"
          type="button"
          className="button button--secondary link-rows__add"
          onClick={() => {
            focusTargetRef.current = `partnerships-${rows.length}-name`
            onChange([...rows, newRow()])
          }}
        >
          إضافة شريك
        </button>
      ) : (
        <p id="add-partner" className="field__hint" tabIndex={-1}>
          وصلت إلى الحد الأقصى ({MAX_PARTNERS} شركاء).
        </p>
      )}
    </div>
  )
}
