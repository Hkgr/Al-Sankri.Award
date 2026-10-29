import type { Errors } from '../basic/formModel'
import type { DomainOption } from './nominationApi'

/*
 * The Stage 2 form, held in memory only (never in localStorage or
 * sessionStorage). Field keys match the backend's validation keys
 * (NominationDetailsRequest), so client and server errors share names.
 * The form asks for the domain and subcategory and the work itself; the
 * earlier questions on funding, partnerships and the places and dates of
 * implementation are retired.
 */

export type NominationForm = {
  domain: string
  subcategory: string
  title: string
  purpose_and_mission: string
  objectives: string
  impact: string
}

export type NominationStepId = 'domain' | 'work' | 'review'

export const NOMINATION_STEPS: { id: NominationStepId; title: string }[] = [
  { id: 'domain', title: 'المحور والفئة' },
  { id: 'work', title: 'تعريف العمل وأثره' },
  { id: 'review', title: 'المراجعة والتأكيد' },
]

export const LIMITS = { title: 255, purpose: 3000, objectives: 3000, impact: 4000 }

export function initialNomination(): NominationForm {
  return {
    domain: '',
    subcategory: '',
    title: '',
    purpose_and_mission: '',
    objectives: '',
    impact: '',
  }
}

export function stepOfNominationField(field: string): NominationStepId {
  const head = field.split('.')[0]
  if (head === 'domain' || head === 'subcategory') return 'domain'
  if (['title', 'purpose_and_mission', 'objectives', 'impact'].includes(head)) return 'work'
  return 'review'
}

/** The DOM id of the control that shows a field's error. */
export function nominationFieldId(key: string): string {
  if (key === 'domain') return 'domain-option-0'
  if (key === 'subcategory') return 'subcategory-option-0'
  return key.replace(/\./g, '-')
}

/** The request body: the form's own fields only. */
export function buildNominationPayload(form: NominationForm, journeyKey: string, accountContext: string): Record<string, unknown> {
  return {
    account_context: accountContext,
    journey_key: journeyKey,
    domain: form.domain,
    subcategory: form.subcategory,
    title: form.title,
    purpose_and_mission: form.purpose_and_mission,
    objectives: form.objectives,
    impact: form.impact,
  }
}

/* ---------- Client-side checks (the server remains authoritative) ---------- */

/** Control characters other than tab and line breaks, which plain text never needs. */
function hasControlCharacters(value: string): boolean {
  return Array.from(value).some((character) => {
    const code = character.charCodeAt(0)
    return (code < 32 && code !== 9 && code !== 10 && code !== 13) || code === 127
  })
}

function length(value: string): number {
  return Array.from(value.trim()).length
}

function checkText(errors: Errors, key: string, value: string, label: string, max: number) {
  if (!value.trim()) {
    errors[key] = `أدخل ${label}.`
  } else if (length(value) > max) {
    errors[key] = `يجب ألا يزيد ${label} على ${max} حرفاً.`
  } else if (hasControlCharacters(value)) {
    errors[key] = `يحتوي ${label} على رموز تحكّم غير مسموح بها. اكتب نصاً عادياً.`
  }
}

export function validateNominationStep(step: NominationStepId, form: NominationForm, domains: DomainOption[]): Errors {
  const errors: Errors = {}

  switch (step) {
    case 'domain': {
      const domain = domains.find((option) => option.code === form.domain)
      if (!domain) errors.domain = 'اختر محور الترشح.'
      else if (!domain.subcategories.some((option) => option.code === form.subcategory)) errors.subcategory = 'اختر فئة الترشح ضمن المحور.'
      break
    }
    case 'work':
      checkText(errors, 'title', form.title, 'عنوان العمل', LIMITS.title)
      checkText(errors, 'purpose_and_mission', form.purpose_and_mission, 'الغاية والرسالة', LIMITS.purpose)
      checkText(errors, 'objectives', form.objectives, 'الأهداف', LIMITS.objectives)
      checkText(errors, 'impact', form.impact, 'الأثر', LIMITS.impact)
      break
    case 'review':
      break
  }

  return errors
}

/** Whether anything was typed or chosen (for the unsaved-changes warning). */
export function nominationHasInput(form: NominationForm): boolean {
  return JSON.stringify(form) !== JSON.stringify(initialNomination())
}
