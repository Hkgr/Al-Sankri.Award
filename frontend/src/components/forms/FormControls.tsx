import { useState } from 'react'
import type { ChangeEvent, ReactNode, Ref } from 'react'
import { describedBy } from './describedBy'

/*
 * Form building blocks that follow identity-guidelines.md §7.2–7.3:
 * persistent labels, "(مطلوب)" markers, errors that start with an icon and
 * a sentence and are linked with aria-describedby, and loading buttons that
 * keep their width.
 */

type FieldProps = {
  id: string
  label: string
  value: string
  onChange: (value: string) => void
  error?: string
  hint?: string
  required?: boolean
  /** Marks the field «(اختياري)»; required fields show «(مطلوب)». */
  optional?: boolean
  type?: 'text' | 'email' | 'password' | 'url' | 'tel'
  autoComplete: string
  inputRef?: Ref<HTMLInputElement>
  readOnly?: boolean
  /** Identifiers, numbers and addresses read left to right inside the RTL page. */
  dir?: 'ltr' | 'auto'
  inputMode?: 'text' | 'numeric' | 'tel' | 'email' | 'url'
  maxLength?: number
}

export function FieldLabel({ htmlFor, label, required, optional }: { htmlFor: string; label: string; required?: boolean; optional?: boolean }) {
  return (
    <label className="field__label" htmlFor={htmlFor}>
      {label}
      {required && <span className="field__required"> (مطلوب)</span>}
      {optional && <span className="field__required"> (اختياري)</span>}
    </label>
  )
}

export function FieldMessages({ id, hint, error }: { id: string; hint?: string; error?: string }) {
  return (
    <>
      {hint && (
        <p id={`${id}-hint`} className="field__hint">
          {hint}
        </p>
      )}
      {error && (
        <p id={`${id}-error`} className="field__error">
          <StatusIcon tone="error" />
          <span>خطأ: {error}</span>
        </p>
      )}
    </>
  )
}

export function TextField({ id, label, value, onChange, error, hint, required, optional, type = 'text', autoComplete, inputRef, readOnly, dir, inputMode, maxLength }: FieldProps) {
  const isLtrValue = type === 'email' || type === 'url' || type === 'tel'
  return (
    <div className="field">
      <FieldLabel htmlFor={id} label={label} required={required} optional={optional} />
      <input
        ref={inputRef}
        id={id}
        name={id}
        className="field__input"
        type={type}
        value={value}
        onChange={(event: ChangeEvent<HTMLInputElement>) => onChange(event.target.value)}
        autoComplete={autoComplete}
        required={required}
        readOnly={readOnly}
        aria-invalid={error ? true : undefined}
        aria-describedby={describedBy(hint && `${id}-hint`, error && `${id}-error`)}
        dir={dir}
        inputMode={inputMode}
        maxLength={maxLength}
        {...(isLtrValue ? { dir: 'ltr', inputMode: type, autoCapitalize: 'none', spellCheck: false } : {})}
      />
      <FieldMessages id={id} hint={hint} error={error} />
    </div>
  )
}

export function PasswordField({ id, label, value, onChange, error, hint, required, autoComplete, inputRef }: Omit<FieldProps, 'type'>) {
  const [visible, setVisible] = useState(false)
  return (
    <div className="field">
      <FieldLabel htmlFor={id} label={label} required={required} />
      <div className="field__password">
        <input
          ref={inputRef}
          id={id}
          name={id}
          className="field__input"
          type={visible ? 'text' : 'password'}
          value={value}
          onChange={(event: ChangeEvent<HTMLInputElement>) => onChange(event.target.value)}
          autoComplete={autoComplete}
          required={required}
          dir="ltr"
          autoCapitalize="none"
          spellCheck={false}
          aria-invalid={error ? true : undefined}
          aria-describedby={describedBy(hint && `${id}-hint`, error && `${id}-error`)}
        />
        <button
          type="button"
          className="field__reveal"
          aria-controls={id}
          aria-pressed={visible}
          onClick={() => setVisible((shown) => !shown)}
        >
          <svg viewBox="0 0 24 24" aria-hidden="true" className="field__reveal-icon">
            <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z" />
            <circle cx="12" cy="12" r="3" />
            {visible && <path d="M4 4l16 16" />}
          </svg>
          <span>{visible ? 'إخفاء' : 'إظهار'}</span>
          <span className="visually-hidden"> كلمة المرور</span>
        </button>
      </div>
      <FieldMessages id={id} hint={hint} error={error} />
    </div>
  )
}

type Tone = 'error' | 'success' | 'info'

export function StatusIcon({ tone }: { tone: Tone }) {
  return (
    <svg className={`status-icon status-icon--${tone}`} viewBox="0 0 20 20" aria-hidden="true" focusable="false">
      <circle cx="10" cy="10" r="8.5" />
      {tone === 'success' ? <path d="M6 10.5l2.6 2.6L14 7.7" /> : tone === 'error' ? <path d="M10 5.5v5.5M10 13.6v.9" /> : <path d="M10 9v5M10 6v.9" />}
    </svg>
  )
}

/**
 * A message block. Errors interrupt (role="alert"); other messages are
 * announced politely. Pass a stable `id` when focus should move to it.
 */
export function FormAlert({ tone, children, id }: { tone: Tone; children: ReactNode; id?: string }) {
  return (
    <div id={id} className={`form-alert form-alert--${tone}`} role={tone === 'error' ? 'alert' : 'status'} tabIndex={id ? -1 : undefined}>
      <StatusIcon tone={tone} />
      <div className="form-alert__body">{children}</div>
    </div>
  )
}

export function SubmitButton({ busy, children, busyLabel, variant = 'primary' }: { busy: boolean; children: ReactNode; busyLabel: string; variant?: 'primary' | 'secondary' }) {
  return (
    <button type="submit" className={`button button--${variant} button--block`} aria-busy={busy || undefined} aria-disabled={busy || undefined}>
      <span className="button__label" data-hidden={busy}>
        {children}
      </span>
      {busy && <span className="button__busy">{busyLabel}</span>}
    </button>
  )
}
