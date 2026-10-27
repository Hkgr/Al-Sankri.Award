import { useEffect, useRef } from 'react'
import type { ReactNode } from 'react'
import { describedBy } from '../../../components/forms/describedBy'
import { FieldLabel, FieldMessages, StatusIcon, TextField } from '../../../components/forms/FormControls'
import type { Country } from '../candidateApi'
import { fieldId, MAX_SOCIAL_LINKS, MONTHS } from './formModel'
import type { Errors, PhoneValue, SocialLink } from './formModel'

/*
 * Form controls for stage 1, built on the same field styles as the account
 * forms (identity-guidelines.md §7.3): visible labels, «(مطلوب)» or
 * «(اختياري)», hints and errors linked with aria-describedby, and grouped
 * controls in fieldsets with legends.
 */

/** Isolates a left-to-right run (calling code, number) inside Arabic option text. */
const ltr = (value: string) => `\u2066${value}\u2069`

function GroupLegend({ label, required, optional }: { label: string; required?: boolean; optional?: boolean }) {
  return (
    <legend className="field__label">
      {label}
      {required && <span className="field__required"> (مطلوب)</span>}
      {optional && <span className="field__required"> (اختياري)</span>}
    </legend>
  )
}

export function SelectField({
  id,
  label,
  value,
  onChange,
  options,
  placeholder,
  error,
  hint,
  autoComplete = 'off',
}: {
  id: string
  label: string
  value: string
  onChange: (value: string) => void
  options: { value: string; label: string }[]
  placeholder: string
  error?: string
  hint?: string
  autoComplete?: string
}) {
  return (
    <div className="field">
      <FieldLabel htmlFor={id} label={label} required />
      <select
        id={id}
        name={id}
        className="field__input field__select"
        value={value}
        onChange={(event) => onChange(event.target.value)}
        required
        autoComplete={autoComplete}
        aria-invalid={error ? true : undefined}
        aria-describedby={describedBy(hint && `${id}-hint`, error && `${id}-error`)}
      >
        <option value="">{placeholder}</option>
        {options.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </select>
      <FieldMessages id={id} hint={hint} error={error} />
    </div>
  )
}

export function TextAreaField({
  id,
  label,
  value,
  onChange,
  error,
  hint,
  maxLength,
  autoComplete,
  optional = false,
  rows = 3,
  limit,
}: {
  id: string
  label: string
  value: string
  onChange: (value: string) => void
  error?: string
  hint?: string
  /** Hard browser limit. Leave unset where text must never be cut silently, and use `limit`. */
  maxLength?: number
  autoComplete: string
  optional?: boolean
  rows?: number
  /** Shown as a live character count; validation enforces it. */
  limit?: number
}) {
  const count = Array.from(value).length
  return (
    <div className="field">
      <FieldLabel htmlFor={id} label={label} required={!optional} optional={optional} />
      <textarea
        id={id}
        name={id}
        className="field__input field__textarea"
        value={value}
        rows={rows}
        maxLength={maxLength}
        onChange={(event) => onChange(event.target.value)}
        autoComplete={autoComplete}
        required={!optional}
        dir="auto"
        aria-invalid={error ? true : undefined}
        aria-describedby={describedBy(hint && `${id}-hint`, limit !== undefined && `${id}-count`, error && `${id}-error`)}
      />
      {limit !== undefined && (
        <p id={`${id}-count`} className="field__hint field__count" data-over={count > limit || undefined}>
          {count} / {limit} حرفاً
        </p>
      )}
      <FieldMessages id={id} hint={hint} error={error} />
    </div>
  )
}

/** A country calling code and a number, each with its own label. */
export function PhoneField({
  name,
  label,
  value,
  onChange,
  countries,
  errors,
  required,
  hint,
}: {
  name: string
  label: string
  value: PhoneValue
  onChange: (value: PhoneValue) => void
  countries: Country[]
  errors: Errors
  required?: boolean
  hint?: string
}) {
  const base = fieldId(name)
  const countryError = errors[`${name}.country`]
  const numberError = errors[`${name}.number`]
  const hintId = hint ? `${base}-hint` : undefined

  return (
    <fieldset className="field field-group">
      <GroupLegend label={label} required={required} optional={!required} />
      {hint && (
        <p id={hintId} className="field__hint">
          {hint}
        </p>
      )}
      <div className="phone-field">
        <div className="field">
          <label className="field__sublabel" htmlFor={`${base}-country`}>
            رمز الدولة
          </label>
          <select
            id={`${base}-country`}
            className="field__input field__select"
            value={value.country}
            onChange={(event) => onChange({ ...value, country: event.target.value })}
            autoComplete="off"
            aria-invalid={countryError ? true : undefined}
            aria-describedby={describedBy(countryError && `${base}-country-error`)}
          >
            {!value.country && <option value="">اختر</option>}
            {countries
              .filter((country) => country.calling_code !== null)
              .map((country) => (
                <option key={country.code} value={country.code}>
                  {`${country.name} ${ltr(`+${country.calling_code}`)}`}
                </option>
              ))}
          </select>
          <FieldMessages id={`${base}-country`} error={countryError} />
        </div>
        <div className="field">
          <label className="field__sublabel" htmlFor={`${base}-number`}>
            الرقم
          </label>
          <input
            id={`${base}-number`}
            className="field__input"
            type="tel"
            dir="ltr"
            inputMode="tel"
            autoComplete={name.endsWith('secondary_phone') ? 'off' : 'tel-national'}
            maxLength={32}
            value={value.number}
            onChange={(event) => onChange({ ...value, number: event.target.value })}
            required={required}
            aria-invalid={numberError ? true : undefined}
            aria-describedby={describedBy(hintId, numberError && `${base}-number-error`)}
          />
          <FieldMessages id={`${base}-number`} error={numberError} />
        </div>
      </div>
    </fieldset>
  )
}

/**
 * Day, month and year as three labelled controls: faster than a calendar
 * picker for a date decades ago, and readable by screen readers.
 */
export function BirthDateField({
  day,
  month,
  year,
  onChange,
  error,
}: {
  day: string
  month: string
  year: string
  onChange: (part: 'birth_day' | 'birth_month' | 'birth_year', value: string) => void
  error?: string
}) {
  const hintId = 'individual-birth_date-hint'
  const errorId = 'individual-birth_date-error'
  const described = describedBy(hintId, error && errorId)

  return (
    <fieldset className="field field-group">
      <GroupLegend label="تاريخ الميلاد" required />
      <p id={hintId} className="field__hint">
        بالتقويم الميلادي، مثلاً: 15 آذار 1985.
      </p>
      <div className="date-field">
        <div className="field">
          <label className="field__sublabel" htmlFor="individual-birth_date-day">
            اليوم
          </label>
          <input
            id="individual-birth_date-day"
            className="field__input date-field__day"
            inputMode="numeric"
            dir="ltr"
            autoComplete="bday-day"
            maxLength={2}
            value={day}
            onChange={(event) => onChange('birth_day', event.target.value)}
            aria-invalid={error ? true : undefined}
            aria-describedby={described}
          />
        </div>
        <div className="field">
          <label className="field__sublabel" htmlFor="individual-birth_date-month">
            الشهر
          </label>
          <select
            id="individual-birth_date-month"
            className="field__input field__select"
            autoComplete="bday-month"
            value={month}
            onChange={(event) => onChange('birth_month', event.target.value)}
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
          <label className="field__sublabel" htmlFor="individual-birth_date-year">
            السنة
          </label>
          <input
            id="individual-birth_date-year"
            className="field__input date-field__year"
            inputMode="numeric"
            dir="ltr"
            autoComplete="bday-year"
            maxLength={4}
            value={year}
            onChange={(event) => onChange('birth_year', event.target.value)}
            aria-invalid={error ? true : undefined}
            aria-describedby={described}
          />
        </div>
      </div>
      <FieldMessages id="individual-birth_date" error={error} />
    </fieldset>
  )
}

export function RadioGroup<T extends string>({
  name,
  label,
  value,
  onChange,
  options,
  error,
}: {
  name: string
  label: string
  value: T | ''
  onChange: (value: T) => void
  options: { value: T; label: string }[]
  error?: string
}) {
  const base = name.replace(/\./g, '-')
  return (
    <fieldset className="field field-group" aria-describedby={error ? `${base}-error` : undefined}>
      <GroupLegend label={label} required />
      <div className="radio-row">
        {options.map((option) => (
          <label key={option.value} className="choice">
            <input
              id={`${base}-${option.value}`}
              type="radio"
              name={base}
              value={option.value}
              checked={value === option.value}
              onChange={() => onChange(option.value)}
              aria-invalid={error ? true : undefined}
            />
            <span>{option.label}</span>
          </label>
        ))}
      </div>
      <FieldMessages id={base} error={error} />
    </fieldset>
  )
}

export function CheckboxField({ id, checked, onChange, error, children }: { id: string; checked: boolean; onChange: (checked: boolean) => void; error?: string; children: ReactNode }) {
  return (
    <div className="field">
      <label className="choice choice--checkbox" htmlFor={id}>
        <input
          id={id}
          type="checkbox"
          checked={checked}
          onChange={(event) => onChange(event.target.checked)}
          aria-invalid={error ? true : undefined}
          aria-describedby={error ? `${id}-error` : undefined}
        />
        <span>{children}</span>
      </label>
      <FieldMessages id={id} error={error} />
    </div>
  )
}

/** Up to five public links, each a platform name and an address. */
export function SocialLinksField({ links, onChange, errors }: { links: SocialLink[]; onChange: (links: SocialLink[]) => void; errors: Errors }) {
  const addRef = useRef<HTMLButtonElement>(null)
  const focusAfterChange = useRef<string | null>(null)

  useEffect(() => {
    if (!focusAfterChange.current) return
    document.getElementById(focusAfterChange.current)?.focus()
    focusAfterChange.current = null
  }, [links])

  const add = () => {
    if (links.length >= MAX_SOCIAL_LINKS) return
    focusAfterChange.current = `social_links-${links.length}-platform`
    onChange([...links, { key: Date.now(), platform: '', url: '' }])
  }

  const remove = (index: number) => {
    focusAfterChange.current = null
    onChange(links.filter((_, position) => position !== index))
    addRef.current?.focus()
  }

  const update = (index: number, part: 'platform' | 'url', value: string) => {
    onChange(links.map((link, position) => (position === index ? { ...link, [part]: value } : link)))
  }

  return (
    <fieldset className="field field-group field-group--full">
      <GroupLegend label="روابط مواقع التواصل الاجتماعي" optional />
      <p className="field__hint" id="social-links-hint">
        حتى {MAX_SOCIAL_LINKS} روابط. اكتب اسم المنصة والرابط الكامل، مثل <bdi dir="ltr">https://www.instagram.com/…</bdi>
      </p>
      {links.length > 0 && (
        <ol className="link-rows">
          {links.map((link, index) => (
            <li key={link.key} className="link-row">
              <TextField
                id={`social_links-${index}-platform`}
                label={`المنصة ${index + 1}`}
                value={link.platform}
                onChange={(value) => update(index, 'platform', value)}
                error={errors[`social_links.${index}.platform`]}
                autoComplete="off"
                maxLength={50}
                dir="auto"
                required
              />
              <TextField
                id={`social_links-${index}-url`}
                label={`الرابط ${index + 1}`}
                type="url"
                value={link.url}
                onChange={(value) => update(index, 'url', value)}
                error={errors[`social_links.${index}.url`]}
                autoComplete="url"
                maxLength={500}
                required
              />
              <button type="button" className="button button--secondary link-row__remove" onClick={() => remove(index)}>
                حذف<span className="visually-hidden"> الرابط {index + 1}</span>
              </button>
            </li>
          ))}
        </ol>
      )}
      {links.length < MAX_SOCIAL_LINKS ? (
        <button ref={addRef} type="button" className="button button--secondary link-rows__add" onClick={add}>
          إضافة رابط
        </button>
      ) : (
        <p className="field__hint">
          <StatusIcon tone="info" /> وصلت إلى الحد الأقصى ({MAX_SOCIAL_LINKS} روابط).
        </p>
      )}
    </fieldset>
  )
}

/**
 * The list of problems on the current step, each a link to its field. It
 * receives focus when it appears, so it is read out first.
 */
export function ErrorSummary({ errors, attempt, idFor = fieldId }: { errors: Errors; attempt: number; idFor?: (key: string) => string }) {
  const ref = useRef<HTMLDivElement>(null)
  const entries = Object.entries(errors)

  useEffect(() => {
    if (attempt > 0 && entries.length > 0) ref.current?.focus()
    // Only a new attempt moves focus, not every keystroke.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [attempt])

  if (entries.length === 0) return null

  return (
    <div ref={ref} className="form-alert form-alert--error error-summary" role="alert" tabIndex={-1} aria-labelledby="error-summary-title">
      <StatusIcon tone="error" />
      <div className="form-alert__body">
        <h2 id="error-summary-title" className="error-summary__title">
          تحقّق من الحقول التالية:
        </h2>
        <ul className="error-summary__list">
          {entries.map(([key, message]) => (
            <li key={key}>
              <a
                href={`#${idFor(key)}`}
                onClick={(event) => {
                  const target = document.getElementById(idFor(key))
                  if (!target) return
                  event.preventDefault()
                  target.focus()
                  target.scrollIntoView({ block: 'center' })
                }}
              >
                {message}
              </a>
            </li>
          ))}
        </ul>
      </div>
    </div>
  )
}
