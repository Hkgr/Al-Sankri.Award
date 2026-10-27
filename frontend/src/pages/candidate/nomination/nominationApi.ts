import cultureAndArts from '../../../assets/brand/domain-culture-and-arts.svg'
import heritage from '../../../assets/brand/domain-tangible-and-intangible-heritage.svg'
import volunteerWork from '../../../assets/brand/domain-volunteer-work.svg'

/**
 * Types, labels and artwork for Stage 2 («فئة الترشح وبيانات العمل»)
 * (backend: /api/candidate/nomination-details).
 */

export type ImplementationState = 'ongoing' | 'completed'

export type FundingType = 'fully_funded' | 'partially_funded' | 'self_funded' | 'not_needed'

export type DomainOption = {
  code: string
  name: string
  artwork: string
  subcategories: { code: string; name: string }[]
}

export type Governorate = { code: string; name: string }

export type Availability = 'available' | 'confirmed' | 'not_configured' | 'ambiguous'

/** The confirmed Stage 2 (NominationDetailsResource). */
export type NominationDetails = {
  confirmed_at: string
  cycle: { code: string; name: string }
  domain: { code: string; name: string; artwork: string }
  subcategory: { code: string; name: string }
  title: string
  purpose_and_mission: string
  objectives: string
  impact: string
  implementation: { state: ImplementationState; started_on: string; ended_on: string | null }
  locations: { country_code: string; governorate: string; governorate_name: string; locality: string; address: string | null }[]
  funding: { type: FundingType; funder_name: string | null; amount_usd: string | null }
  partnerships: { has_partnerships: boolean; items: { name: string; contribution: string | null }[] }
}

export type NominationState = {
  data: NominationDetails | null
  availability: { status: Availability }
  cycle?: { code: string; name: string }
  journey_key?: string
  options?: {
    domains: DomainOption[]
    country: { code: string; name: string }
    governorates: Governorate[]
  }
}

export const FUNDING_LABELS: Record<FundingType, string> = {
  fully_funded: 'ممول بالكامل',
  partially_funded: 'ممول جزئيًا',
  self_funded: 'تمويل شخصي',
  not_needed: 'لا يحتاج تمويل',
}

/**
 * The approved award artwork per domain artwork key. The files are the
 * original extracted SVGs; Creativity uses the former Volunteer Work shape,
 * as approved by the owner. The alt text names the domain's award.
 */
const ARTWORK: Record<string, string> = {
  'domain-culture-and-arts': cultureAndArts,
  'domain-tangible-and-intangible-heritage': heritage,
  'domain-volunteer-work': volunteerWork,
}

export function artworkFor(key: string): string | null {
  return ARTWORK[key] ?? null
}

export function artworkLabel(domainName: string): string {
  return `جائزة محور ${domainName}`
}
