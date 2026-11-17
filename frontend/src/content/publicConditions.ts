import { awardTerms } from './awardTerms.ts'

/** The public page groups shared conditions once, above the category-specific ones. */
function forPublicPages(locale: 'ar' | 'en') {
  const content = awardTerms[locale]
  const portalCondition = content.general[5].text
  return {
    ...content,
    domains: content.domains.map((domain) => ({
      ...domain,
      subcategories: domain.subcategories.map((category) => ({
        ...category,
        requirements: category.code === 'poetry' ? category.requirements.filter((condition) => condition !== portalCondition) : category.requirements,
      })),
    })),
  }
}

export const publicConditions = { ar: forPublicPages('ar'), en: forPublicPages('en') }

/** Keep the organization link structured in the numbered conditions as well as the warning. */
export function generalConditions(locale: 'ar' | 'en') {
  const label = locale === 'ar' ? 'مؤسسة الرواد' : 'Al-Rowad Foundation'
  return awardTerms[locale].general.map((condition, index) => {
    const at = index === 4 ? condition.text.indexOf(label) : -1
    return { ...condition, text: at < 0 ? [condition.text] : [condition.text.slice(0, at), { org: 'rowad' as const, label }, condition.text.slice(at + label.length)] }
  })
}
