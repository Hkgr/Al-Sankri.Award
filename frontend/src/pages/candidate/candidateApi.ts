/**
 * Types and calls for the candidate dashboard (backend: routes/api.php,
 * prefix /api/candidate). Every endpoint concerns the signed-in account only.
 */

import type { User } from '../../auth/useAuth'

export type ProfileType = 'individual' | 'institution'

export type StageStatus = 'not_started' | 'completed' | 'unavailable'

export type Overview = {
  basic_information: {
    status: StageStatus
    completed_at: string | null
    profile_type: ProfileType | null
  }
  /** Stage 2: statuses only (no work details, places or funding). */
  nomination_details: {
    status: 'locked' | 'unavailable' | 'not_started' | 'completed'
    reason: string | null
    confirmed_at: string | null
  }
}

export type PolicyCode = 'privacy_policy' | 'terms_of_use'

export type PolicySummary = {
  id: number
  code: PolicyCode
  version_number: number
  title: string
  published_at: string | null
}

export type Requirements = {
  email_verified: boolean
  policies: Record<PolicyCode, PolicySummary | null>
  finalization_available: boolean
}

export type Country = { code: string; name: string; calling_code: number | null }

type CountryValue = { code: string; name: string | null } | null

/** The confirmed basic information (BasicProfileResource). */
export type BasicProfile = {
  profile_type: ProfileType
  completed_at: string
  individual?: {
    first_name: string
    middle_name: string | null
    last_name: string
    birth_date: string
    nationality: CountryValue
    gender: 'male' | 'female'
    national_id_number: string
    residence_country: CountryValue
    address: string
    primary_phone: string
    secondary_phone: string | null
    contact_email: string
  }
  institution?: {
    name: string
    country: CountryValue
    activity_type: string
    license_number: string
    representative_name: string | null
    representative_title: string | null
    phone: string
    email: string
    website: string | null
  }
  social_links: { platform: string; url: string }[]
  accepted_policies: { code: PolicyCode; version_number: number; title: string; accepted_at: string }[]
  declarations: { data_accuracy_declared_at: string; representation_declared_at: string | null }
}

export const POLICY_SLUGS: Record<PolicyCode, string> = {
  privacy_policy: 'privacy-policy',
  terms_of_use: 'terms-of-use',
}

export const POLICY_NAMES: Record<PolicyCode, string> = {
  privacy_policy: 'سياسة الخصوصية',
  terms_of_use: 'شروط الاستخدام',
}

export const GENDER_LABELS = { male: 'ذكر', female: 'أنثى' } as const

export const PROFILE_TYPE_LABELS: Record<ProfileType, string> = { individual: 'فرد', institution: 'مؤسسة' }

/**
 * The header candidate reads carry: the account the page shows. The server
 * answers 409 account_mismatch if another account is signed in now.
 */
export function contextHeader(user: Pick<User, 'account_context'>): Record<string, string> {
  return { 'X-Candidate-Context': user.account_context }
}

/** Dates and times are stored in UTC and shown in Damascus time. */
export const DAMASCUS_TIME_ZONE = 'Asia/Damascus'

const LOCALE = 'ar-SY-u-nu-latn-ca-gregory'

export function formatDamascusDateTime(iso: string): string {
  return new Intl.DateTimeFormat(LOCALE, {
    timeZone: DAMASCUS_TIME_ZONE,
    day: 'numeric',
    month: 'long',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  }).format(new Date(iso))
}

/** A calendar date (YYYY-MM-DD), shown without any time-zone shift. */
export function formatCalendarDate(date: string): string {
  const [year, month, day] = date.split('-').map(Number)
  return new Intl.DateTimeFormat(LOCALE, { timeZone: 'UTC', day: 'numeric', month: 'long', year: 'numeric' }).format(
    new Date(Date.UTC(year, month - 1, day)),
  )
}
