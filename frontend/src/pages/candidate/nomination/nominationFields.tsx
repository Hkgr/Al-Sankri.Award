import type { ReactNode } from 'react'
import { FieldMessages, StatusIcon } from '../../../components/forms/FormControls'
import type { Errors } from '../basic/formModel'
import { artworkFor, artworkLabel } from './nominationApi'
import type { DomainOption } from './nominationApi'

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
