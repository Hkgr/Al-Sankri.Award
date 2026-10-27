import { asciiDigits } from '../basic/formModel'
import type { Errors } from '../basic/formModel'
import type { DomainOption, FundingType } from './nominationApi'

/*
 * The Stage 2 form, held in memory only (never in localStorage or
 * sessionStorage). Field keys match the backend's validation keys
 * (NominationDetailsRequest), so client and server errors share names.
 */

export type DateParts = { day: string; month: string; year: string }

export type LocationRow = { key: number; governorate: string; locality: string; address: string }

export type PartnerRow = { key: number; name: string; contribution: string }

export type NominationForm = {
  domain: string
  subcategory: string
  title: string
  purpose_and_mission: string
  objectives: string
  impact: string
  ongoing: boolean
  start: DateParts
  end: DateParts
  locations: LocationRow[]
  funding_type: FundingType | ''
  funder_name: string
  funding_amount: string
  has_partnerships: '' | 'yes' | 'no'
  partners: PartnerRow[]
}

export type NominationStepId = 'domain' | 'work' | 'dates_locations' | 'funding' | 'review'

export const NOMINATION_STEPS: { id: NominationStepId; title: string }[] = [
  { id: 'domain', title: 'المحور والفئة' },
  { id: 'work', title: 'تعريف العمل وأثره' },
  { id: 'dates_locations', title: 'التواريخ وأماكن التنفيذ' },
  { id: 'funding', title: 'التمويل والشراكات' },
  { id: 'review', title: 'المراجعة والتأكيد' },
]

export const LIMITS = { title: 255, purpose: 3000, objectives: 3000, impact: 4000, locality: 150, address: 500, funder: 255, partner: 255, contribution: 1000 }

export const MAX_LOCATIONS = 20

export const MAX_PARTNERS = 10

/** Funding types that name a funder and report its amount. */
export const FUNDED_BY_OTHERS: FundingType[] = ['fully_funded', 'partially_funded']

let rowKey = 0
export const nextKey = () => ++rowKey

const emptyDate = (): DateParts => ({ day: '', month: '', year: '' })

export function emptyLocation(): LocationRow {
  return { key: nextKey(), governorate: '', locality: '', address: '' }
}

export function emptyPartner(): PartnerRow {
  return { key: nextKey(), name: '', contribution: '' }
}

export function initialNomination(): NominationForm {
  return {
    domain: '',
    subcategory: '',
    title: '',
    purpose_and_mission: '',
    objectives: '',
    impact: '',
    ongoing: false,
    start: emptyDate(),
    end: emptyDate(),
    locations: [emptyLocation()],
    funding_type: '',
    funder_name: '',
    funding_amount: '',
    has_partnerships: '',
    partners: [],
  }
}

export function stepOfNominationField(field: string): NominationStepId {
  const head = field.split('.')[0]
  if (head === 'domain' || head === 'subcategory') return 'domain'
  if (['title', 'purpose_and_mission', 'objectives', 'impact'].includes(head)) return 'work'
  if (['implementation_state', 'started_on', 'ended_on', 'locations'].includes(head)) return 'dates_locations'
  return 'funding'
}

/** The DOM id of the control that shows a field's error. */
export function nominationFieldId(key: string): string {
  if (key === 'domain') return 'domain-option-0'
  if (key === 'subcategory') return 'subcategory-option-0'
  if (key === 'implementation_state') return 'ongoing'
  if (key === 'started_on' || key === 'ended_on') return `${key}-day`
  if (key === 'locations') return 'add-location'
  if (key === 'partnerships') return 'add-partner'
  if (key === 'has_partnerships') return 'has_partnerships-yes'
  return key.replace(/\./g, '-')
}

export function dateValue(parts: DateParts): string {
  const day = asciiDigits(parts.day).trim()
  const year = asciiDigits(parts.year).trim()
  if (!day || !parts.month || !year) return ''
  return `${year.padStart(4, '0')}-${parts.month.padStart(2, '0')}-${day.padStart(2, '0')}`
}

/** "١٢٠٠٫٥" → "1200.5": digits and the Arabic decimal separator only. */
export function normalizeAmount(value: string): string {
  return asciiDigits(value).trim().replace(/\u066B/g, '.')
}

/** The request body: only the fields that apply to the answers given. */
export function buildNominationPayload(form: NominationForm, journeyKey: string, accountContext: string): Record<string, unknown> {
  const funding = form.funding_type
  const payload: Record<string, unknown> = {
    account_context: accountContext,
    journey_key: journeyKey,
    domain: form.domain,
    subcategory: form.subcategory,
    title: form.title,
    purpose_and_mission: form.purpose_and_mission,
    objectives: form.objectives,
    impact: form.impact,
    implementation_state: form.ongoing ? 'ongoing' : 'completed',
    started_on: dateValue(form.start),
    locations: form.locations.map((row) => ({ governorate: row.governorate, locality: row.locality, address: row.address.trim() || null })),
    funding_type: funding,
    has_partnerships: form.has_partnerships === '' ? null : form.has_partnerships === 'yes',
  }
  if (!form.ongoing) payload.ended_on = dateValue(form.end)
  if (funding && FUNDED_BY_OTHERS.includes(funding)) payload.funder_name = form.funder_name
  if (funding && funding !== 'not_needed') payload.funding_amount = normalizeAmount(form.funding_amount)
  if (form.has_partnerships === 'yes') {
    payload.partnerships = form.partners.map((row) => ({ name: row.name, contribution: row.contribution.trim() || null }))
  }
  return payload
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

function checkText(errors: Errors, key: string, value: string, label: string, max: number, required = true) {
  if (!value.trim()) {
    if (required) errors[key] = `أدخل ${label}.`
  } else if (length(value) > max) {
    errors[key] = `يجب ألا يزيد ${label} على ${max} حرفاً.`
  } else if (hasControlCharacters(value)) {
    errors[key] = `يحتوي ${label} على رموز تحكّم غير مسموح بها. اكتب نصاً عادياً.`
  }
}

/** Today in Damascus as YYYY-MM-DD. */
export function damascusToday(): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Damascus' }).format(new Date())
}

function checkDate(errors: Errors, key: string, parts: DateParts, missing: string, future: string) {
  const year = asciiDigits(parts.year).trim()
  const day = asciiDigits(parts.day).trim()
  if (!day || !parts.month || !year) {
    errors[key] = missing
    return
  }
  const value = dateValue(parts)
  const [y, m, d] = value.split('-').map(Number)
  const date = new Date(Date.UTC(y, m - 1, d))
  if (!/^\d{4}$/.test(year) || !/^\d{1,2}$/.test(day) || date.getUTCFullYear() !== y || date.getUTCMonth() !== m - 1 || date.getUTCDate() !== d) {
    errors[key] = key === 'started_on' ? 'أدخل تاريخ بدء حقيقياً بالتقويم الميلادي.' : 'أدخل تاريخ انتهاء حقيقياً بالتقويم الميلادي.'
  } else if (y < 1900) {
    errors[key] = 'أدخل سنة من 1900 أو بعدها.'
  } else if (value > damascusToday()) {
    errors[key] = future
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
    case 'dates_locations': {
      checkDate(errors, 'started_on', form.start, 'أدخل تاريخ بدء العمل كاملاً: اليوم والشهر والسنة.', 'لا يمكن أن يكون تاريخ بدء العمل في المستقبل.')
      if (!form.ongoing) {
        checkDate(errors, 'ended_on', form.end, 'أدخل تاريخ انتهاء العمل، أو اختر «العمل مستمر».', 'لا يمكن أن يكون تاريخ انتهاء العمل في المستقبل.')
        if (!errors.started_on && !errors.ended_on && dateValue(form.end) < dateValue(form.start)) {
          errors.ended_on = 'لا يمكن أن يسبق تاريخ الانتهاء تاريخ البدء.'
        }
      }
      if (form.locations.length === 0) errors.locations = 'أضف مكان تنفيذ واحداً على الأقل.'
      const seen = new Set<string>()
      form.locations.forEach((row, index) => {
        if (!row.governorate) errors[`locations.${index}.governorate`] = 'اختر المحافظة.'
        checkText(errors, `locations.${index}.locality`, row.locality, 'المدينة أو البلدة أو القرية', LIMITS.locality)
        checkText(errors, `locations.${index}.address`, row.address, 'العنوان أو تفاصيل المكان', LIMITS.address, false)
        const key = [row.governorate, row.locality, row.address].map((part) => part.trim().replace(/\s+/g, ' ').toLowerCase()).join('|')
        if (seen.has(key) && !errors[`locations.${index}.locality`]) errors[`locations.${index}.locality`] = 'مكان التنفيذ هذا مكرّر. احذفه أو غيّر بياناته.'
        seen.add(key)
      })
      break
    }
    case 'funding': {
      const funding = form.funding_type
      if (!funding) errors.funding_type = 'اختر الإقرار المالي.'
      if (funding && FUNDED_BY_OTHERS.includes(funding)) checkText(errors, 'funder_name', form.funder_name, 'اسم الجهة الممولة', LIMITS.funder)
      if (funding && funding !== 'not_needed') {
        const amount = normalizeAmount(form.funding_amount)
        if (!amount) errors.funding_amount = 'أدخل المبلغ بالدولار الأمريكي.'
        else if (!/^\d{1,12}(\.\d{1,2})?$/.test(amount)) {
          errors.funding_amount = 'اكتب المبلغ بالأرقام، مع نقطة للكسور العشرية عند الحاجة، مثل 1500 أو 1500.50، ودون فواصل للآلاف.'
        } else if (!/[1-9]/.test(amount)) errors.funding_amount = 'يجب أن يكون المبلغ أكبر من صفر.'
      }
      if (!form.has_partnerships) errors.has_partnerships = 'أجب: هل توجد شراكات مرتبطة بالعمل؟'
      if (form.has_partnerships === 'yes') {
        if (form.partners.length === 0) errors.partnerships = 'أضف شريكاً واحداً على الأقل، أو اختر «لا».'
        form.partners.forEach((row, index) => {
          checkText(errors, `partnerships.${index}.name`, row.name, 'اسم الجهة الشريكة', LIMITS.partner)
          checkText(errors, `partnerships.${index}.contribution`, row.contribution, 'طبيعة الشراكة أو المساهمة', LIMITS.contribution, false)
        })
      }
      break
    }
    case 'review':
      break
  }

  return errors
}

/** Whether anything was typed or chosen (for the unsaved-changes warning). */
export function nominationHasInput(form: NominationForm): boolean {
  const empty = initialNomination()
  // Row keys are generated identifiers, not input.
  const strip = (value: NominationForm) => ({
    ...value,
    locations: value.locations.map((row) => [row.governorate, row.locality, row.address]),
    partners: value.partners.map((row) => [row.name, row.contribution]),
  })
  return JSON.stringify(strip(form)) !== JSON.stringify(strip(empty))
}
