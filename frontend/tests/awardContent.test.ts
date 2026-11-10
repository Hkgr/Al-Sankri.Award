import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { createHash } from 'node:crypto'
import { awardTerms, categoryRequirements } from '../src/content/awardTerms.ts'
import { publicConditions, generalConditions } from '../src/content/publicConditions.ts'
import { ar as removalAr, en as removalEn } from '../src/i18n/catalogs/accountRemoval.ts'
import { ar as updateAr, en as updateEn } from '../src/i18n/catalogs/awardUpdate.ts'

test('the approved conditions are complete in both languages and use the same six category codes', () => {
  const codes = ['poetry', 'story', 'tangible_heritage', 'intangible_heritage', 'social_responsibility', 'entrepreneurship']
  for (const locale of ['ar', 'en'] as const) {
    assert.equal(awardTerms[locale].general.length, 7)
    assert.deepEqual(awardTerms[locale].domains.flatMap((domain) => domain.subcategories.map((category) => category.code)), codes)
    assert.deepEqual(codes.map((code) => categoryRequirements(locale, code).length), [4, 4, 2, 2, 2, 3])
  }
  assert.match(categoryRequirements('en', 'poetry').join(' '), /previously/)
  assert.match(categoryRequirements('en', 'entrepreneurship').join(' '), /sustainab/i)
  assert.doesNotMatch(JSON.stringify(awardTerms.en), /[\u0600-\u06ff]/)
  assert.deepEqual(categoryRequirements('en', 'quality'), [])
})

test('public formatting and all category guidance consume the same shared conditions without changing their text', () => {
  const shared = JSON.parse(readFileSync(new URL('../../backend/resources/award/terms-2026-11-10.json', import.meta.url), 'utf8'))
  assert.deepEqual(awardTerms, shared)
  assert.strictEqual(publicConditions, awardTerms)
  for (const locale of ['ar', 'en'] as const) {
    const before = awardTerms[locale].domains.map((domain) => [domain.code, domain.subcategories.map((category) => category.code).sort()])
    const after = publicConditions[locale].domains.map((domain) => [domain.code, domain.subcategories.map((category) => category.code).sort()])
    assert.deepEqual(after, before)
    for (const domain of publicConditions[locale].domains) {
      for (const category of domain.subcategories) assert.deepEqual(categoryRequirements(locale, category.code), category.requirements)
    }
    assert.equal(publicConditions[locale].general.length, 7)
    generalConditions(locale).forEach((condition, index) => {
      assert.equal(condition.text.map((part) => typeof part === 'string' ? part : part.label).join(''), publicConditions[locale].general[index].text)
    })
    assert.deepEqual(generalConditions(locale)[4].text.filter((part) => typeof part !== 'string'), [{ org: 'rowad', label: locale === 'ar' ? 'مؤسسة الرواد' : 'Al-Rowad Foundation' }])
  }
  assert.doesNotMatch(JSON.stringify(publicConditions.en), /[\u0600-\u06ff]/)
})

test('new deletion and consent messages have complete English and Arabic catalog coverage', () => {
  const ar = { ...removalAr, ...updateAr }
  const en = { ...removalEn, ...updateEn }
  for (const key of Object.keys(ar)) {
    assert.ok(en[key], key)
    assert.doesNotMatch(en[key], /[\u0600-\u06ff]/, key)
  }
})

test('the legacy official PNG source remains intact for the Arabic reports', () => {
  const web = readFileSync(new URL('../src/assets/brand/award-logo-official.png', import.meta.url))
  const report = readFileSync(new URL('../../backend/resources/reports/award-logo-official.png', import.meta.url))
  assert.equal(web.readUInt32BE(16), 3561)
  assert.equal(web.readUInt32BE(20), 1203)
  assert.equal(createHash('sha256').update(web).digest('hex'), createHash('sha256').update(report).digest('hex'))
})
