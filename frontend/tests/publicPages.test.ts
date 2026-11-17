import assert from 'node:assert/strict'
import { after, before, test } from 'node:test'
import { createElement } from 'react'
import { renderToStaticMarkup } from 'react-dom/server'
import { createServer } from 'vite'
import type { ViteDevServer } from 'vite'

let server: ViteDevServer
let render: (page: 'home' | 'journey' | 'poetry' | 'governance', locale: 'ar' | 'en') => string

const copy = {
  ar: {
    preparation: 'التحضيرات والتهيئة لإطلاق الجائزة قبل فتح باب الترشح.',
    flow: 'يصل كل ملف مرسل إلى إدارة الجائزة أولاً للتدقيق، ثم يحال إلى لجنة تحكيم متخصصة بمحوره الرئيسي، وتتألف كل لجنة من 3 أو 5 محكّمين.',
    anonymityTitle: 'إخفاء الهوية في النصوص والوثائق',
    anonymity: 'يجب إخفاء هوية المترشح من النصوص والوثائق المقدّمة للتحكيم، وإزالة اسمه وتوقيعه وأي بيانات أو إشارات تدلّ على صاحبها.',
    journeyLink: 'مسيرة الجائزة',
  },
  en: {
    preparation: 'Preparations and groundwork for launching the award before applications open.',
    flow: 'Every submitted application first reaches the award administration for review, then is referred to a judging committee specializing in its main domain. Each committee consists of 3 or 5 judges.',
    anonymityTitle: 'Anonymity in texts and documents',
    anonymity: 'The candidate’s identity must be concealed in the texts and documents submitted for judging. Their name, signature, and any data or indications that identify their author must be removed.',
    journeyLink: 'Award journey',
  },
}

before(async () => {
  server = await createServer({ server: { middlewareMode: true }, appType: 'custom' })
  const { HomePage } = await server.ssrLoadModule('/src/pages/home/HomePage.tsx')
  const { JourneyPage } = await server.ssrLoadModule('/src/pages/public/JourneyPage.tsx')
  const { GovernancePage } = await server.ssrLoadModule('/src/pages/public/GovernancePage.tsx')
  const { EligibilityDomainPage } = await server.ssrLoadModule('/src/pages/public/EligibilityPage.tsx')
  const { AuthContext } = await server.ssrLoadModule('/src/auth/useAuth.ts')
  const { LocaleContext } = await server.ssrLoadModule('/src/i18n/useLocale.ts')
  const { RouterContext } = await server.ssrLoadModule('/src/lib/useRouter.ts')
  const { translate } = await server.ssrLoadModule('/src/i18n/messages.ts')
  const { domains } = await server.ssrLoadModule('/src/content/site.ts')
  const pages = { home: HomePage, journey: JourneyPage, governance: GovernancePage }
  render = (page, locale) => renderToStaticMarkup(
    createElement(LocaleContext.Provider, { value: { locale, dir: locale === 'ar' ? 'rtl' : 'ltr', t: (key: string, vars?: Record<string, string | number>) => translate(locale, key, vars), setLocale: () => {} } },
      createElement(AuthContext.Provider, { value: { user: null } },
        createElement(RouterContext.Provider, { value: { location: { pathname: '/', search: '', hash: '' } } },
          page === 'poetry' ? createElement(EligibilityDomainPage, { domain: domains[0] }) : createElement(pages[page]),
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
    assert.ok(html.includes(copy[locale].preparation))
  })

  test(`the ${locale} homepage preserves its slogan and useful journey links without a countdown`, () => {
    const html = render('home', locale)
    assert.doesNotMatch(html, /countdown|role="timer"|aria-live|timeline__status|announced later|مخطط|موعدها/)
    const text = html.replace(/<[^>]+>/g, '')
    assert.match(text, locale === 'ar' ? /وفاءٌ للأثر\.\.\. تمكينٌ للمبادرة/ : /Loyalty to impact\.\.\. empowering initiative/)
    assert.match(html, /href="\/journey"/)
    assert.match(html, /href="\/eligibility\/culture-and-arts#category-poetry"/)
    assert.doesNotMatch(text, /المغمورة|العامودي/)
    assert.ok(text.includes(copy[locale].preparation))
    assert.ok(text.includes(copy[locale].flow))
    assert.match(text, locale === 'ar' ? /البيانات الأساسية، ثم فئة الترشح وبيانات العمل، ثم ملفات العمل وأدلة أثره/ : /basic information, then nomination category and work details, then work files and evidence of impact/)
  })

  test(`the ${locale} poetry page renders three specific conditions and the general portal rule once`, () => {
    const html = render('poetry', locale)
    const poetry = html.slice(html.indexOf('id="category-poetry"')).split('</ol>')[0]
    assert.equal((poetry.match(/<li[ >]/g) ?? []).length, 3)
    const portal = locale === 'ar' ? 'أن يتقدّم بمشاركته والوثائق والبيانات المطلوبة عبر البوابة الرقمية للجائزة.' : 'The participation, required documents and information must be submitted through the award’s digital portal.'
    assert.equal(html.split(portal).length - 1, 1)
    assert.doesNotMatch(html, /العامودي/)
    assert.ok(html.includes(copy[locale].anonymityTitle))
    assert.ok(html.includes(copy[locale].anonymity))
    assert.ok(html.includes(copy[locale].flow))
  })

  test(`the ${locale} governance links to the process-only journey and describes the committee size`, () => {
    const html = render('governance', locale)
    const link = html.match(/<a\b[^>]*href="\/journey"[^>]*>([\s\S]*?)<\/a>/)?.[1]
    assert.equal(link?.trim(), copy[locale].journeyLink)
    assert.match(html, locale === 'ar' ? /3 أو 5 محكّمين متخصصين لكل محور رئيسي/ : /3 or 5 specialist judges for each main domain/)
    for (const page of ['home', 'governance'] as const) {
      for (const match of render(page, locale).matchAll(/<a\b[^>]*href="\/journey"[^>]*>([\s\S]*?)<\/a>/g)) {
        assert.doesNotMatch(match[1], /موعد|مواعيد|dates/i)
      }
    }
  })
}
