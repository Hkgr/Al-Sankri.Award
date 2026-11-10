import terms from '../../../backend/resources/award/terms-2026-11-10.json' with { type: 'json' }

/** One versioned source for public pages, category guidance and server-side consent. */
export const awardTerms = terms

export function categoryRequirements(locale: 'ar' | 'en', code: string): string[] {
  return terms[locale].domains.flatMap((domain) => domain.subcategories).find((category) => category.code === code)?.requirements ?? []
}
