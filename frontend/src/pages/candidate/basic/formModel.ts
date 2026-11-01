import type { Country, PolicyCode, ProfileType, Requirements } from '../candidateApi'
import { tr } from '../../../i18n/messages'

/*
 * The stage 1 form, held in memory only (never in localStorage or
 * sessionStorage). Field keys match the backend's validation keys
 * (BasicProfileRequest), so client and server errors use the same names.
 */

export type PhoneValue = { country: string; number: string }

export type SocialLink = { key: number; platform: string; url: string }

export type IndividualForm = {
  first_name: string
  middle_name: string
  last_name: string
  birth_day: string
  birth_month: string
  birth_year: string
  nationality: string
  gender: '' | 'male' | 'female'
  national_id_number: string
  residence_country: string
  address: string
  primary_phone: PhoneValue
  secondary_phone: PhoneValue
  contact_email: string
  social_links: SocialLink[]
}

export type InstitutionForm = {
  name: string
  country: string
  activity_type: string
  license_number: string
  representative_name: string
  representative_title: string
  phone: PhoneValue
  email: string
  website: string
  social_links: SocialLink[]
}

export type Declarations = Record<PolicyCode, boolean> & { data_accuracy: boolean; representation: boolean }

export type FormState = {
  profileType: ProfileType | ''
  individual: IndividualForm
  institution: InstitutionForm
  declarations: Declarations
}

export type StepId = 'type' | 'individual_identity' | 'individual_contact' | 'institution_details' | 'institution_contact' | 'review'

export type Errors = Record<string, string>

export const MAX_SOCIAL_LINKS = 5

/** Gregorian month names as used in Syria. */
export const MONTHS = [
  'كانون الثاني',
  'شباط',
  'آذار',
  'نيسان',
  'أيار',
  'حزيران',
  'تموز',
  'آب',
  'أيلول',
  'تشرين الأول',
  'تشرين الثاني',
  'كانون الأول',
]

export function countryOptions(countries: Country[]) {
  return countries.map((country) => ({ value: country.code, label: country.name }))
}

/** The default calling code; every number has its own selectable code. */
const DEFAULT_PHONE_COUNTRY = 'SY'

export const STEPS: Record<ProfileType, { id: StepId; title: string }[]> = {
  individual: [
    { id: 'type', title: 'نوع المتقدم' },
    { id: 'individual_identity', title: 'البيانات الشخصية' },
    { id: 'individual_contact', title: 'الإقامة والتواصل' },
    { id: 'review', title: 'المراجعة والإقرارات' },
  ],
  institution: [
    { id: 'type', title: 'نوع المتقدم' },
    { id: 'institution_details', title: 'بيانات المؤسسة' },
    { id: 'institution_contact', title: 'الممثل والتواصل' },
    { id: 'review', title: 'المراجعة والإقرارات' },
  ],
}

export function stepsFor(type: ProfileType | ''): { id: StepId; title: string }[] {
  return (type ? STEPS[type] : [{ id: 'type' as const, title: 'نوع المتقدم' }]).map((step) => ({ ...step, title: tr(step.title) }))
}

/** The step that holds a field, so a server error can lead back to it. */
export function stepOfField(field: string): StepId {
  const [group, name] = field.split('.')
  if (group === 'profile_type') return 'type'
  if (group === 'individual') {
    return ['first_name', 'middle_name', 'last_name', 'birth_date', 'nationality', 'gender', 'national_id_number'].includes(name)
      ? 'individual_identity'
      : 'individual_contact'
  }
  if (group === 'institution') {
    return ['name', 'country', 'activity_type', 'license_number'].includes(name) ? 'institution_details' : 'institution_contact'
  }
  return 'review'
}

export function emptyIndividual(loginEmail: string): IndividualForm {
  return {
    first_name: '',
    middle_name: '',
    last_name: '',
    birth_day: '',
    birth_month: '',
    birth_year: '',
    nationality: '',
    gender: '',
    national_id_number: '',
    residence_country: '',
    address: '',
    primary_phone: { country: DEFAULT_PHONE_COUNTRY, number: '' },
    secondary_phone: { country: DEFAULT_PHONE_COUNTRY, number: '' },
    // An editable suggestion; it never changes the login email.
    contact_email: loginEmail,
    social_links: [],
  }
}

export function emptyInstitution(): InstitutionForm {
  return {
    name: '',
    country: '',
    activity_type: '',
    license_number: '',
    representative_name: '',
    representative_title: '',
    phone: { country: DEFAULT_PHONE_COUNTRY, number: '' },
    email: '',
    website: '',
    social_links: [],
  }
}

export function initialState(loginEmail: string): FormState {
  return {
    profileType: '',
    individual: emptyIndividual(loginEmail),
    institution: emptyInstitution(),
    declarations: { privacy_policy: false, terms_of_use: false, data_accuracy: false, representation: false },
  }
}

/** Whether a branch holds anything the person typed (beyond the defaults). */
export function branchHasInput(state: FormState, type: ProfileType, loginEmail: string): boolean {
  const empty = type === 'individual' ? emptyIndividual(loginEmail) : emptyInstitution()
  return JSON.stringify(state[type]) !== JSON.stringify(empty)
}

/** Converts Arabic-Indic (٠–٩) and Persian (۰–۹) digits to 0–9. */
export function asciiDigits(value: string): string {
  return value.replace(/[٠-٩۰-۹]/g, (digit) => String(digit.charCodeAt(0) & 0xf))
}

export function birthDate(individual: IndividualForm): string {
  const day = asciiDigits(individual.birth_day).trim()
  const month = individual.birth_month
  const year = asciiDigits(individual.birth_year).trim()
  if (!day || !month || !year) return ''
  return `${year.padStart(4, '0')}-${month.padStart(2, '0')}-${day.padStart(2, '0')}`
}

function phonePayload(phone: PhoneValue): PhoneValue | null {
  const number = asciiDigits(phone.number).trim()
  return number ? { country: phone.country, number } : null
}

function linksPayload(links: SocialLink[]) {
  return links.map((link) => ({ platform: link.platform.trim(), url: link.url.trim() }))
}

/**
 * The request body: only the selected branch is sent, with the account
 * context of the form (the server refuses it for any other account).
 */
export function buildPayload(state: FormState, requirements: Requirements | null, accountContext: string): Record<string, unknown> {
  const accepted: Partial<Record<PolicyCode, number>> = {}
  for (const code of ['privacy_policy', 'terms_of_use'] as PolicyCode[]) {
    const policy = requirements?.policies[code]
    if (policy && state.declarations[code]) accepted[code] = policy.id
  }
  const declarations: Record<string, boolean> = { data_accuracy: state.declarations.data_accuracy }

  if (state.profileType === 'individual') {
    const person = state.individual
    return {
      account_context: accountContext,
      profile_type: 'individual',
      individual: {
        first_name: person.first_name,
        middle_name: person.middle_name,
        last_name: person.last_name,
        birth_date: birthDate(person),
        nationality: person.nationality,
        gender: person.gender,
        national_id_number: asciiDigits(person.national_id_number),
        residence_country: person.residence_country,
        address: person.address,
        primary_phone: { country: person.primary_phone.country, number: asciiDigits(person.primary_phone.number).trim() },
        secondary_phone: phonePayload(person.secondary_phone),
        contact_email: person.contact_email.trim(),
      },
      social_links: linksPayload(person.social_links),
      accepted_policies: accepted,
      declarations,
    }
  }

  const institution = state.institution
  declarations.representation = state.declarations.representation
  return {
    account_context: accountContext,
    profile_type: 'institution',
    institution: {
      name: institution.name,
      country: institution.country,
      activity_type: institution.activity_type,
      license_number: asciiDigits(institution.license_number),
      representative_name: institution.representative_name,
      representative_title: institution.representative_title,
      phone: { country: institution.phone.country, number: asciiDigits(institution.phone.number).trim() },
      email: institution.email.trim(),
      website: institution.website.trim(),
    },
    social_links: linksPayload(institution.social_links),
    accepted_policies: accepted,
    declarations,
  }
}

/* ---------- Client-side checks (the server remains authoritative) ---------- */

const PERSON_NAME = /^[\p{L}\p{M}](?:[\p{L}\p{M}\u2019'. -]|\u200C|\u200D)*$/u
const IDENTIFIER = /^[\p{L}\p{N}][\p{L}\p{N}/. -]*$/u
const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

function length(value: string): number {
  return Array.from(value.trim()).length
}

function checkName(errors: Errors, key: string, value: string, label: string, max: number, required = true) {
  const trimmed = value.trim()
  if (!trimmed) {
    if (required) errors[key] = tr('أدخل {label}.', { label })
  } else if (length(trimmed) > max) {
    errors[key] = tr('يجب ألا يزيد {label} على {max} حرفاً.', { label, max })
  } else if (!PERSON_NAME.test(trimmed.replace(/\s+/g, ' '))) {
    errors[key] = tr('استخدم الحروف والمسافات وعلامات الأسماء المعتادة فقط في {label}.', { label })
  }
}

function checkText(errors: Errors, key: string, value: string, label: string, max: number) {
  if (!value.trim()) errors[key] = tr('أدخل {label}.', { label })
  else if (length(value) > max) errors[key] = tr('يجب ألا يزيد {label} على {max} حرفاً.', { label, max })
}

function checkIdentifier(errors: Errors, key: string, value: string, label: string) {
  const trimmed = asciiDigits(value).trim()
  if (!trimmed) errors[key] = tr('أدخل {label}.', { label })
  else if (length(trimmed) > 50) errors[key] = tr('يجب ألا يزيد {label} على 50 حرفاً.', { label })
  else if (!IDENTIFIER.test(trimmed)) errors[key] = tr('استخدم الحروف والأرقام والفواصل المعتادة (- / .) فقط في {label}.', { label })
}

function checkEmail(errors: Errors, key: string, value: string, label: string) {
  const trimmed = value.trim()
  if (!trimmed) errors[key] = tr('أدخل {label}.', { label })
  else if (trimmed.length > 255 || !EMAIL.test(trimmed)) errors[key] = tr('أدخل {label} صحيحاً، مثل name@example.com.', { label })
}

function checkPhone(errors: Errors, key: string, phone: PhoneValue, label: string, required: boolean) {
  const digits = asciiDigits(phone.number).replace(/[^\d]/g, '')
  if (!phone.number.trim()) {
    if (required) errors[`${key}.number`] = tr('أدخل {label}.', { label })
    return
  }
  if (!phone.country) errors[`${key}.country`] = tr('اختر رمز الدولة.')
  if (digits.length < 4 || digits.length > 17 || /[^\d\s()+.-]/.test(asciiDigits(phone.number))) {
    errors[`${key}.number`] = tr('رقم الهاتف غير صحيح لرمز الدولة المختار. تحقّق من الرقم ورمز الدولة.')
  }
}

export function isWebUrl(value: string): boolean {
  try {
    const url = new URL(value.trim())
    return (url.protocol === 'https:' || url.protocol === 'http:') && url.hostname.includes('.') && !url.username && !url.password && !/\s/.test(value.trim())
  } catch {
    return false
  }
}

function checkLinks(errors: Errors, links: SocialLink[]) {
  links.forEach((link, index) => {
    if (!link.platform.trim()) errors[`social_links.${index}.platform`] = tr('أدخل اسم المنصة.')
    else if (length(link.platform) > 50) errors[`social_links.${index}.platform`] = tr('يجب ألا يزيد اسم المنصة على 50 حرفاً.')
    if (!link.url.trim()) errors[`social_links.${index}.url`] = tr('أدخل الرابط.')
    else if (link.url.trim().length > 500 || !isWebUrl(link.url)) errors[`social_links.${index}.url`] = tr('أدخل رابطاً كاملاً يبدأ بـ https:// أو http://.')
  })
}

function checkBirthDate(errors: Errors, person: IndividualForm, today: Date) {
  const key = 'individual.birth_date'
  const day = Number(asciiDigits(person.birth_day).trim())
  const month = Number(person.birth_month)
  const yearText = asciiDigits(person.birth_year).trim()
  const year = Number(yearText)
  if (!person.birth_day.trim() || !person.birth_month || !yearText) {
    errors[key] = tr('أدخل تاريخ الميلاد كاملاً: اليوم والشهر والسنة.')
    return
  }
  const date = new Date(Date.UTC(year, month - 1, day))
  if (!/^\d{4}$/.test(yearText) || !Number.isInteger(day) || date.getUTCFullYear() !== year || date.getUTCMonth() !== month - 1 || date.getUTCDate() !== day) {
    errors[key] = tr('أدخل تاريخ ميلاد حقيقياً بالتقويم الميلادي.')
  } else if (year < 1900) {
    errors[key] = tr('أدخل سنة ميلاد من 1900 أو بعدها.')
  } else if (date.getTime() > today.getTime()) {
    errors[key] = tr('لا يمكن أن يكون تاريخ الميلاد في المستقبل.')
  }
}

/** Today's date in Damascus, as a UTC midnight. */
function damascusToday(): Date {
  const [year, month, day] = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Damascus' }).format(new Date()).split('-').map(Number)
  return new Date(Date.UTC(year, month - 1, day))
}

export function validateStep(step: StepId, state: FormState, requirements: Requirements | null): Errors {
  const errors: Errors = {}
  const person = state.individual
  const institution = state.institution

  switch (step) {
    case 'type':
      if (!state.profileType) errors.profile_type = tr('اختر نوع المتقدم: فرد أو مؤسسة.')
      break
    case 'individual_identity':
      checkName(errors, 'individual.first_name', person.first_name, tr('الاسم الأول'), 80)
      checkName(errors, 'individual.middle_name', person.middle_name, tr('الاسم الأوسط'), 80, false)
      checkName(errors, 'individual.last_name', person.last_name, tr('الاسم الأخير'), 80)
      checkBirthDate(errors, person, damascusToday())
      if (!person.nationality) errors['individual.nationality'] = tr('اختر الجنسية.')
      if (!person.gender) errors['individual.gender'] = tr('اختر الجنس.')
      checkIdentifier(errors, 'individual.national_id_number', person.national_id_number, tr('رقم الهوية الشخصية'))
      break
    case 'individual_contact':
      if (!person.residence_country) errors['individual.residence_country'] = tr('اختر دولة الإقامة.')
      checkText(errors, 'individual.address', person.address, tr('العنوان'), 500)
      checkPhone(errors, 'individual.primary_phone', person.primary_phone, tr('رقم التواصل الأساسي'), true)
      checkPhone(errors, 'individual.secondary_phone', person.secondary_phone, tr('رقم التواصل الاحتياطي'), false)
      checkEmail(errors, 'individual.contact_email', person.contact_email, tr('البريد الإلكتروني للتواصل'))
      checkLinks(errors, person.social_links)
      break
    case 'institution_details':
      checkText(errors, 'institution.name', institution.name, tr('اسم المؤسسة'), 255)
      if (!institution.country) errors['institution.country'] = tr('اختر دولة المؤسسة.')
      checkText(errors, 'institution.activity_type', institution.activity_type, tr('نوع النشاط المؤسسي'), 150)
      checkIdentifier(errors, 'institution.license_number', institution.license_number, tr('رقم الترخيص'))
      break
    case 'institution_contact':
      checkName(errors, 'institution.representative_name', institution.representative_name, tr('اسم الشخص المتقدم بالنيابة عن المؤسسة'), 150)
      checkText(errors, 'institution.representative_title', institution.representative_title, tr('الصفة الوظيفية'), 150)
      checkPhone(errors, 'institution.phone', institution.phone, tr('رقم تواصل المؤسسة'), true)
      checkEmail(errors, 'institution.email', institution.email, tr('البريد الإلكتروني للمؤسسة'))
      if (institution.website.trim() && (institution.website.trim().length > 500 || !isWebUrl(institution.website))) {
        errors['institution.website'] = tr('أدخل رابطاً كاملاً يبدأ بـ https:// أو http://.')
      }
      checkLinks(errors, institution.social_links)
      break
    case 'review':
      for (const code of ['privacy_policy', 'terms_of_use'] as PolicyCode[]) {
        if (!requirements?.policies[code]) errors[`accepted_policies.${code}`] = tr('النص المعتمد غير منشور بعد.')
        else if (!state.declarations[code]) {
          errors[`accepted_policies.${code}`] = code === 'privacy_policy' ? tr('وافق على سياسة الخصوصية للمتابعة.') : tr('وافق على شروط الاستخدام للمتابعة.')
        }
      }
      if (!state.declarations.data_accuracy) errors['declarations.data_accuracy'] = tr('أكّد صحة البيانات المقدمة للمتابعة.')
      if (state.profileType === 'institution' && !state.declarations.representation) {
        errors['declarations.representation'] = tr('أكّد أنك مخوَّل بتمثيل المؤسسة للمتابعة.')
      }
      break
  }

  return errors
}

/** The DOM id of the control that shows a field's error. */
export function fieldId(key: string): string {
  if (key === 'individual.birth_date') return 'individual-birth_date-day'
  if (key === 'profile_type') return 'profile_type-individual'
  if (key === 'individual.gender') return 'individual-gender-male'
  if (key.startsWith('accepted_policies.')) return `declaration-${key.split('.')[1]}`
  if (key.startsWith('declarations.')) return `declaration-${key.split('.')[1]}`
  return key.replace(/\./g, '-')
}
