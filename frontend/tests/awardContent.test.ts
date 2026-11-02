import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { createHash } from 'node:crypto'
import { awardTerms, categoryRequirements } from '../src/content/awardTerms.ts'
import { ar as removalAr, en as removalEn } from '../src/i18n/catalogs/accountRemoval.ts'
import { ar as updateAr, en as updateEn } from '../src/i18n/catalogs/awardUpdate.ts'

test('the approved conditions are complete in both languages and use the same six category codes', () => {
  const codes = ['poetry', 'story', 'tangible_heritage', 'intangible_heritage', 'entrepreneurship', 'social_responsibility']
  for (const locale of ['ar', 'en'] as const) {
    assert.equal(awardTerms[locale].general.length, 5)
    assert.deepEqual(awardTerms[locale].domains.flatMap((domain) => domain.subcategories.map((category) => category.code)), codes)
    assert.deepEqual(codes.map((code) => categoryRequirements(locale, code).length), [3, 3, 2, 2, 3, 3])
  }
  assert.match(categoryRequirements('en', 'poetry').join(' '), /previously/)
  assert.match(categoryRequirements('en', 'social_responsibility').join(' '), /financial and procedural transparency/i)
  assert.match(categoryRequirements('en', 'entrepreneurship').join(' '), /sustainab/i)
  assert.doesNotMatch(JSON.stringify(awardTerms.en), /[\u0600-\u06ff]/)
  assert.deepEqual(categoryRequirements('en', 'quality'), [])
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
