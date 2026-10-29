import cultureAndArts from '../../../assets/brand/domain-culture-and-arts.svg'
import heritage from '../../../assets/brand/domain-tangible-and-intangible-heritage.svg'
import volunteerWork from '../../../assets/brand/domain-volunteer-work.svg'

/**
 * Types, labels and artwork for Stage 2 («فئة الترشح وبيانات العمل»)
 * (backend: /api/candidate/nomination-details).
 */

export type DomainOption = {
  code: string
  name: string
  artwork: string
  subcategories: { code: string; name: string }[]
}

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
}

export type NominationState = {
  data: NominationDetails | null
  availability: { status: Availability }
  cycle?: { code: string; name: string }
  journey_key?: string
  options?: {
    domains: DomainOption[]
  }
}

/**
 * What became of one form's save (GET /api/candidate/nomination-details/journey,
 * for that form's journey key only).
 */
export type JourneyOutcome = {
  data: NominationDetails | null
  journey: { status: 'confirmed' | 'open' | 'changed' | 'unavailable' }
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
