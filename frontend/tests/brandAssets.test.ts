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
