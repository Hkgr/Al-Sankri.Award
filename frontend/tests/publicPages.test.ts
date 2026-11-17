import assert from 'node:assert/strict'
import { after, before, test } from 'node:test'
import { createElement } from 'react'
import { renderToStaticMarkup } from 'react-dom/server'
import { createServer } from 'vite'
import type { ViteDevServer } from 'vite'

let server: ViteDevServer
let render: (page: 'home' | 'journey' | 'poetry', locale: 'ar' | 'en') => string

before(async () => {
  server = await createServer({ server: { middlewareMode: true }, appType: 'custom' })
  const { HomePage } = await server.ssrLoadModule('/src/pages/home/HomePage.tsx')
  const { JourneyPage } = await server.ssrLoadModule('/src/pages/public/JourneyPage.tsx')
  const { EligibilityDomainPage } = await server.ssrLoadModule('/src/pages/public/EligibilityPage.tsx')
  const { AuthContext } = await server.ssrLoadModule('/src/auth/useAuth.ts')
  const { LocaleContext } = await server.ssrLoadModule('/src/i18n/useLocale.ts')
  const { RouterContext } = await server.ssrLoadModule('/src/lib/useRouter.ts')
  const { translate } = await server.ssrLoadModule('/src/i18n/messages.ts')
  const { domains } = await server.ssrLoadModule('/src/content/site.ts')
  render = (page, locale) => renderToStaticMarkup(
    createElement(LocaleContext.Provider, { value: { locale, dir: locale === 'ar' ? 'rtl' : 'ltr', t: (key: string, vars?: Record<string, string | number>) => translate(locale, key, vars), setLocale: () => {} } },
      createElement(AuthContext.Provider, { value: { user: null } },
        createElement(RouterContext.Provider, { value: { location: { pathname: '/', search: '', hash: '' } } },
          page === 'poetry' ? createElement(EligibilityDomainPage, { domain: domains[0] }) : createElement(page === 'home' ? HomePage : JourneyPage),
        ),
      ),
    ),
  )
})

after(async () => { await server?.close() })

for (const locale of ['ar', 'en'] as const) {
  test(`the ${locale} journey renders only the ordered stages and descriptions`, () => {
    const html = render('journey', locale)
    assert.equal((html.match(/class="timeline__phase"/g) ?? []).length, 5)
    assert.equal((html.match(/class="timeline__marker"/g) ?? []).length, 5)
    assert.doesNotMatch(html, /data-tone|aria-current="step"|role="progressbar"|<time|timeline__date|timeline__status/)
    assert.doesNotMatch(html, /نوفمبر|ديسمبر|بتوقيت|موعد|مخطط|جارية|انتهت|November|December|Damascus time|announced later|planned|upcoming|in progress/i)
    assert.match(html, locale === 'ar' ? /إعداد وثائقها ومعاييرها ومنصتها الإلكترونية/ : /preparing its documents, criteria and online platform/)
  })

  test(`the ${locale} homepage preserves its slogan and useful journey links without a countdown`, () => {
    const html = render('home', locale)
    assert.doesNotMatch(html, /countdown|role="timer"|aria-live|timeline__status|announced later|مخطط|موعدها/)
    const text = html.replace(/<[^>]+>/g, '')
    assert.match(text, locale === 'ar' ? /وفاءٌ للأثر\.\.\. تمكينٌ للمبادرة/ : /Loyalty to impact\.\.\. empowering initiative/)
    assert.match(html, /href="\/journey"/)
    assert.match(html, /href="\/eligibility\/culture-and-arts#category-poetry"/)
    assert.doesNotMatch(text, /المغمورة|العامودي/)
  })

  test(`the ${locale} poetry page renders three specific conditions and the general portal rule once`, () => {
    const html = render('poetry', locale)
    const poetry = html.slice(html.indexOf('id="category-poetry"')).split('</ol>')[0]
    assert.equal((poetry.match(/<li[ >]/g) ?? []).length, 3)
    const portal = locale === 'ar' ? 'أن يتقدّم بمشاركته والوثائق والبيانات المطلوبة عبر البوابة الرقمية للجائزة.' : 'The participation, required documents and information must be submitted through the award’s digital portal.'
    assert.equal(html.split(portal).length - 1, 1)
    assert.doesNotMatch(html, /العامودي/)
  })
}
