import { awardTerms } from './awardTerms.ts'

/** Formatting wrapper over the same approved source used by guidance and server-side consent. */
export const publicConditions = awardTerms

/** Keep the organization link structured in the numbered conditions as well as the warning. */
export function generalConditions(locale: 'ar' | 'en') {
  const label = locale === 'ar' ? 'مؤسسة الرواد' : 'Al-Rowad Foundation'
  return awardTerms[locale].general.map((condition, index) => {
    const at = index === 4 ? condition.text.indexOf(label) : -1
    return { ...condition, text: at < 0 ? [condition.text] : [condition.text.slice(0, at), { org: 'rowad' as const, label }, condition.text.slice(at + label.length)] }
  })
}
