import terms from './conditions-2026-11-10.json' with { type: 'json' }

/** Approved public website copy. Platform guidance, consent and validation retain their existing source. */
export const publicConditions = terms

const conflict = {
  ar: ['حظر ترشح أعضاء مجلس الأمناء، ولجان التحكيم، ولجنة النزاهة، وموظفي إدارة الجائزة ب', { org: 'rowad' as const, label: 'مؤسسة الرواد' }, ' ضمانًا للحياد والنزاهة.'],
  en: ['Members of the board of trustees, judging committees and integrity committee, and award administration staff at ', { org: 'rowad' as const, label: 'Al-Rowad Foundation' }, ' are prohibited from applying, to ensure impartiality and integrity.'],
}

/** Keep the organization link structured in the numbered conditions as well as the warning. */
export function generalConditions(locale: 'ar' | 'en') {
  return terms[locale].general.map((condition, index) => ({ ...condition, text: index === 4 ? conflict[locale] : [condition.text] }))
}
