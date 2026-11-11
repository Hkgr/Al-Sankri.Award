import test from 'node:test'
import assert from 'node:assert/strict'
import { existsSync, readFileSync } from 'node:fs'

type Derivative = { file: string; width: number; height: number }
type Source = { key: string; kind: string; width: number; height: number; derivatives: Derivative[] }

const manifest = JSON.parse(readFileSync(new URL('../../docs/design/brand-originals/manifest.json', import.meta.url), 'utf8')) as { sources: Source[] }
// brandAssets.ts imports image files, which Node cannot load; its declarations are checked as text.
const brandAssets = readFileSync(new URL('../src/content/brandAssets.ts', import.meta.url), 'utf8')

test('each domain shows its own vertical trophy at the derivative’s real dimensions', () => {
  const trophies = manifest.sources.filter((source) => source.kind === 'trophy')
  assert.deepEqual(trophies.map((source) => source.key).sort(), ['creativity', 'culture-and-arts', 'heritage'])

  const prefix = { 'culture-and-arts': 'culture', heritage: 'heritage', creativity: 'creativity' } as Record<string, string>
  for (const source of trophies) {
    assert.ok(source.height > source.width, `${source.key} is vertical`)
    assert.deepEqual(source.derivatives.map((derivative) => derivative.width), [400, 800])
    for (const derivative of source.derivatives) {
      assert.ok(existsSync(new URL(`../../${derivative.file}`, import.meta.url)), derivative.file)
      assert.match(brandAssets, new RegExp(`import ${prefix[source.key]}${derivative.width} from '\\.\\./assets/brand/official/trophy-${source.key}-${derivative.width}\\.webp'`))
    }
    const large = source.derivatives[1]
    const key = source.key.includes('-') ? `'${source.key}'` : source.key
    assert.match(brandAssets, new RegExp(`${key}: trophy\\(${prefix[source.key]}400, ${prefix[source.key]}800, ${large.height}\\)`))
  }
  assert.match(brandAssets, /width: 800,/)
  assert.doesNotMatch(brandAssets, /679|-480\.webp|-960\.webp/)
})

test('each partner logo is imported at its trimmed derivative size and links to its own organization', () => {
  const partners = manifest.sources.filter((source) => source.kind === 'partner')
  assert.deepEqual(partners.map((source) => source.key), ['rowad', 'aleppo-culture-directorate', 'university-of-aleppo'])
  for (const source of partners) {
    const [derivative] = source.derivatives
    assert.equal(source.derivatives.length, 1)
    assert.equal(derivative.height, 160)
    assert.ok(existsSync(new URL(`../../${derivative.file}`, import.meta.url)), derivative.file)
    assert.match(brandAssets, new RegExp(`from '\\.\\./assets/brand/official/partner-${source.key}-${derivative.width}\\.webp'`))
    const key = source.key.includes('-') ? `'${source.key}'` : source.key
    assert.match(brandAssets, new RegExp(`${key}: \\{ src: \\w+, width: ${derivative.width}, height: ${derivative.height} \\}`))
  }

  const site = readFileSync(new URL('../src/content/site.ts', import.meta.url), 'utf8')
  assert.match(site, /\{ key: 'rowad', name: organizations\.rowad\.name, role: 'implementing', url: organizations\.rowad\.url \}/)
  assert.match(site, /\{ key: 'aleppo-culture-directorate', name: 'مديرية الثقافة في حلب', role: 'participating', url: 'https:\/\/www\.facebook\.com\/AleppoCulture\/' \}/)
  assert.match(site, /\{ key: 'university-of-aleppo', name: 'جامعة حلب', role: 'participating', url: 'https:\/\/alepuniv\.edu\.sy\/' \}/)
})
