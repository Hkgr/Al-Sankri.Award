/*
 * Public website copy and data.
 *
 * Award information comes from the supplied documents in docs/:
 *   plan    خطة جائزة الدكتور عبد القادر سنكري للثقافة والتراث والتنمية المجتمعية.docx
 *   current conditions: shared approved update, 2026-11-10 (awardTerms.ts)
 *   launch  التصور التنفيذي لحفل الإطلاق الرسمي.docx
 *   guide   جائزة السنكري visual identity.pdf (physical page numbers)
 * and the owner's decisions of 2026-10-29 and 2026-11-02, which override older documents:
 * the official name and slogan, three main domains with six
 * subcategories, no public voting and no field verification, review by
 * award administration before referral to a committee of 3 or 5 judges.
 * Biographical statements come from HAND's and Al-Rowad's published pages
 * (docs/design/photo-provenance.md). Nothing here is invented: no dates
 * beyond the confirmed schedule, figures, quotations, winners or events.
 */

import domainCultureArts from '../assets/brand/domain-culture-and-arts.svg'
import domainHeritage from '../assets/brand/domain-tangible-and-intangible-heritage.svg'
import domainVolunteer from '../assets/brand/domain-volunteer-work.svg'
import portrait400 from '../assets/photos/sankari-portrait-400.webp'
import portrait640 from '../assets/photos/sankari-portrait-640.webp'
import portrait800 from '../assets/photos/sankari-portrait-800.webp'
import portrait800Jpg from '../assets/photos/sankari-portrait-800.jpg'
import visit960 from '../assets/photos/sankari-umayyad-mosque-visit-960.webp'
import visit1600 from '../assets/photos/sankari-umayyad-mosque-visit-1600.webp'
import visit1600Jpg from '../assets/photos/sankari-umayyad-mosque-visit-1600.jpg'
import visitNarrow480 from '../assets/photos/sankari-umayyad-mosque-visit-narrow-480.webp'
import visitNarrow912 from '../assets/photos/sankari-umayyad-mosque-visit-narrow-912.webp'
import visitNarrow912Jpg from '../assets/photos/sankari-umayyad-mosque-visit-narrow-912.jpg'
import type { PhotoSource } from '../components/Photo'
import { publicConditions, generalConditions } from './publicConditions'

export const award = {
  name: 'جائزة الدكتور عبد القادر سنكري للثقافة والتراث والإبداع',
  shortName: 'جائزة الدكتور عبد القادر سنكري',
  slogan: 'وفاءٌ للأثر... تمكينٌ للمبادرة',
}

/** Organisations named in public prose; each is rendered as an external link (OrgLink). */
export type OrganizationKey = 'rowad' | 'hand'

export const organizations: Record<OrganizationKey, { name: string; url: string }> = {
  rowad: { name: 'مؤسسة الروّاد للتعاون والتنمية', url: 'https://www.alrowadngo.sy/' },
  hand: { name: 'منظمة HAND', url: 'https://www.hand.ngo/' },
}

/** Prose that may name an organisation: plain strings and organisation links, in order. */
export type RichText = (string | { org: OrganizationKey; label?: string })[]

export type SitePage = { href: string; label: string; labelEn?: string; description: string }

/** The four main pages, in navigation order. */
export const pages: SitePage[] = [
  {
    href: '/',
    label: 'الرئيسية',
    labelEn: 'Home',
    description:
      'جائزة الدكتور عبد القادر سنكري للثقافة والتراث والإبداع: جائزة سنوية تكتشف المبادرات والأفراد والفرق التي تصنع أثراً حقيقياً موثقاً في سوريا وتكرّمها، وتنفّذها مؤسسة الروّاد للتعاون والتنمية.',
  },
  {
    href: '/about',
    label: 'عن الجائزة',
    labelEn: 'About the award',
    description: 'غاية الجائزة وقيمها، وتركيزها على الأثر الموثق، والجهة المنفذة، وصلتها بمسيرة الدكتور عبد القادر سنكري في العطاء الإنساني والتنموي.',
  },
  {
    href: '/journey',
    label: 'مسيرة الجائزة',
    labelEn: 'Award journey',
    description: 'مراحل الجائزة وتسلسلها من الإعداد إلى استقبال ملفات الترشح والتدقيق الإداري والتحكيم المستقل والتكريم.',
  },
  {
    href: '/eligibility',
    label: 'شروط التقدم',
    labelEn: 'Eligibility',
    description: 'شروط التقدم المشتركة لمحاور الجائزة الثلاثة، والوثائق وأدلة الأثر المطلوبة، وقواعد منع تضارب المصالح، وشروط الفئات كاملة.',
  },
  {
    href: '/governance',
    label: 'الحوكمة',
    labelEn: 'Governance',
    description: 'منظومة الحوكمة في جائزة الدكتور عبد القادر سنكري: فحص الأهلية المبدئي، ولجان التحكيم المستقلة، والتحقق الميداني، ومصادقة مجلس الأمناء على النتائج.',
  },
  {
    href: '/media',
    label: 'المركز الإعلامي',
    labelEn: 'Media Center',
    description: 'المركز الإعلامي لجائزة الدكتور عبد القادر سنكري: أخبار الجائزة وبياناتها الرسمية، وشعاراتها الرسمية للاستخدام الإعلامي.',
  },
]

/** A main page by its path; the navigation order may change, so pages are never looked up by position. */
export function pageAt(list: SitePage[], href: string): SitePage {
  const page = list.find((item) => item.href === href)
  if (!page) throw new Error(`Unknown page ${href}`)
  return page
}

export type PartnerKey = 'rowad' | 'aleppo-culture-directorate' | 'university-of-aleppo'

/** The implementing organization first, then the participating ones (owner decision, 2026-11-11). */
export type Partner = { key: PartnerKey; name: string; role: 'implementing' | 'participating'; url: string }

export const partners: Partner[] = [
  { key: 'rowad', name: organizations.rowad.name, role: 'implementing', url: organizations.rowad.url },
  { key: 'aleppo-culture-directorate', name: 'مديرية الثقافة في حلب', role: 'participating', url: 'https://www.facebook.com/AleppoCulture/' },
  { key: 'university-of-aleppo', name: 'جامعة حلب', role: 'participating', url: 'https://alepuniv.edu.sy/' },
]

export const hero = {
  // Terms: «الفلسفة والغاية الأساسية».
  lead: 'منصة سنوية سورية تكتشف المبادرات والأفراد والفرق التي تعمل بصمت وتصنع أثراً حقيقياً موثقاً، فتكرّمها وتربطها بفرص التمكين والتدريب والتشبيك.',
  portrait: {
    sources: [{ srcSet: `${portrait400} 400w, ${portrait640} 640w, ${portrait800} 800w`, type: 'image/webp' }] satisfies PhotoSource[],
    fallback: portrait800Jpg,
    width: 800,
    height: 790,
    alt: 'صورة شخصية للدكتور عبد القادر سنكري مبتسماً، يرتدي الغترة البيضاء والعقال ونظارة طبية.',
    caption: 'الدكتور عبد القادر سنكري',
  },
}

export type Subcategory = { code: string; label: string; requirements: string[] }

export type MainDomain = {
  slug: 'culture-and-arts' | 'heritage' | 'creativity'
  label: string
  /** The approved award artwork. Creativity uses the former Volunteer Work shape (owner decision). */
  symbol: string
  /** Domain description from the current conditions. */
  intro: string
  subcategories: Subcategory[]
}

// Three main domains and six subcategories (owner decision; plan and terms tables).
export const domains: MainDomain[] = publicConditions.ar.domains.map((domain, index) => ({
  slug: (['culture-and-arts', 'heritage', 'creativity'] as const)[index],
  label: domain.name,
  symbol: [domainCultureArts, domainHeritage, domainVolunteer][index],
  intro: domain.intro,
  subcategories: domain.subcategories.map((category) => ({ code: category.code, label: category.name, requirements: category.requirements })),
}))

export function domainBySlug(slug: string): MainDomain | undefined {
  return domains.find((domain) => domain.slug === slug)
}

export const about = {
  // Terms: «الفلسفة والغاية الأساسية».
  philosophy:
    'تبرز الجائزة قيمةً أخلاقية تجسّد الوفاء لمسيرة الدكتور عبد القادر سنكري في العطاء الإنساني والتنموي، وتهدف إلى تأسيس منصة سنوية سورية موثوقة تكتشف المبادرات المنسيّة والأفراد والفرق التي تعمل بصمت وتصنع أثراً حقيقياً، وتكرّمها.',
  // Guide p. 16 (closing page).
  statement: 'لا تحتفي الجائزة بالإنجاز بوصفه نتيجة فحسب، بل بالقيمة التي يصنعها، وبالذاكرة التي يحفظها، وبالمبادرة التي تفتح طريقاً لغيرها.',
  // Terms and launch plan: governance commitments.
  values: [
    { title: 'الوفاء', text: 'وفاءٌ لمسيرة عطاء إنساني وتنموي، وللأثر الذي يصنعه أصحابه بعيداً عن الأضواء.' },
    { title: 'الأثر الموثق', text: 'تكرّم الجائزة ما تحقق فعلاً وتثبته الأدلة، لا الأفكار النظرية أو المقترحات التي لم يبدأ تنفيذها.' },
    { title: 'النزاهة والشفافية', text: 'حوكمة واضحة، ولجان مستقلة، ومنع صارم لتضارب المصالح في كل مراحل الجائزة.' },
    { title: 'التمكين', text: 'التكريم بداية لا نهاية: فرص للتدريب والتشبيك والنمو بعد مرحلة التكريم.' },
  ],
  // Terms: «شرط الأثر الموثق» and the evidence the platform asks for.
  impact:
    'يقوم التقدم إلى الجائزة على أثر ملموس وموثق في الواقع: تغيير إيجابي أحدثه العمل في حياة المستفيدين والمجتمع، تثبته الملفات التوثيقية والتقارير والصور ومقاطع الفيديو والشهادات. لذلك تُبنى ملفات الترشح على الأدلة، ويُقيَّم كل ملف وفق معايير معلنة.',
  // Terms: «الجهة المنفذة».
  organization: [
    { org: 'rowad' },
    ' مؤسسة غير حكومية مرخّصة في سوريا، تركّز على تحويل العمل الإنساني إلى عمل تنموي مستدام يضع الإنسان في قلب أولوياته، وهي الجهة المنفذة للجائزة.',
  ] satisfies RichText,
}

export const legacy = {
  // HAND, "USD 40 Million Donation by Dr. Sankari…" (20 Dec 2025), and
  // "In the historic surroundings of the Umayyad Mosque…" (11 May 2026).
  paragraphs: [
    [
      'غادر الدكتور عبد القادر سنكري مدينة حلب عام 1970 لدراسة الصيدلة في الإسكندرية والقاهرة، ثم بدأ مسيرته المهنية محاضراً في جامعة الإمارات العربية المتحدة في العين. وهو رجل أعمال إماراتي من أصل سوري، أسّس ',
      { org: 'rowad' },
      ' و',
      { org: 'hand' },
      ' ويرأسهما، ومن خلالهما نُفّذت مبادرات إنسانية وتنموية في التعليم والإغاثة والتمكين الاقتصادي وبناء القدرات.',
    ],
    [
      'ومن بين ما نفّذته المؤسسات التي يرأسها إعادة تأهيل الجامع الأموي في دمشق بما يحفظ قيمته التاريخية والدينية. وقد عُرف بدعمه الطويل للمبادرات الإنسانية والخيرية في سوريا، مع حرصه في كثير من الأحيان على أن يبقى عطاؤه بعيداً عن الأضواء.',
    ],
  ] satisfies RichText[],
  bridge: 'ومن هذا المعنى تنطلق الجائزة التي تحمل اسمه: أن يُرى الأثر الحقيقي، وأن يُكرَّم أصحابه وإن عملوا بعيداً عن الأضواء.',
  photo: {
    sources: [
      { media: '(max-width: 47.99rem)', srcSet: `${visitNarrow480} 480w, ${visitNarrow912} 912w`, type: 'image/webp', width: 912, height: 1110 },
      { media: '(max-width: 47.99rem)', srcSet: `${visitNarrow912Jpg} 912w`, width: 912, height: 1110 },
      { srcSet: `${visit960} 960w, ${visit1600} 1600w`, type: 'image/webp' },
    ] satisfies PhotoSource[],
    fallback: visit1600Jpg,
    width: 1600,
    height: 1017,
    alt: 'الدكتور عبد القادر سنكري بالزي الإماراتي يقف مع مرافقيه في قاعة من قاعات الجامع الأموي، تعلوهم كتابة قرآنية بالفسيفساء.',
    caption: 'خلال زيارته الجامع الأموي في دمشق في أيار/مايو 2026، وهي أول زيارة له إلى دمشق منذ عام 2011، برفقة عدد من المسؤولين وممثلي المؤسستين.',
  },
}

/** Confirmed operational dates retained independently of the public journey. */
export const confirmedSchedule = {
  deadline: 'يغلق باب الترشح بنهاية يوم 15 نوفمبر 2026 — بتوقيت دمشق',
  judging: 'فترة التحكيم: من 16 إلى 25 نوفمبر 2026',
}

export type JourneyPhase = {
  key: 'preparation' | 'application' | 'screening' | 'judging' | 'recognition'
  title: string
  summary: string
  points: string[]
}

// Plan: «مسارات إطلاق الجائزة» and «مراحل الجائزة», without public voting or field visits.
export const journey: JourneyPhase[] = [
  {
    key: 'preparation',
    title: 'الإعداد',
    summary: 'التخطيط للجائزة وإعداد وثائقها ومعاييرها ومنصتها الإلكترونية.',
    points: ['لقاءات تشاورية مع مؤسسات رسمية ومنظمات من المجتمع المدني.', 'التخطيط للجائزة وإعداد وثائقها ومعاييرها.', 'إعداد المنصة الإلكترونية للترشح.'],
  },
  {
    key: 'application',
    title: 'الإطلاق واستقبال الترشيحات',
    summary: 'فتح المنصة الإلكترونية لاستقبال ملفات الترشح.',
    points: [
      'التقديم عبر المنصة: إنشاء حساب، ثم البيانات الأساسية، ففئة الترشح وبيانات العمل، فملفات العمل وأدلة أثره، ثم «إرسال ملف الترشح».',
      'جلسات تعريفية وورش عمل يعقدها فريق الجائزة لتوجيه المتقدمين في استكمال متطلبات الترشح وتجهيز الأدلة، وفق ما تعلنه إدارة الجائزة.',
    ],
  },
  {
    key: 'screening',
    title: 'التدقيق الإداري',
    summary: 'يصل كل ملف مرسل إلى إدارة الجائزة أولاً.',
    points: [
      'مراجعة أولية لمطابقة الملف للشروط الفنية والقانونية واكتمال بياناته ووثائقه.',
      'تحيل إدارة الجائزة الملفات المستوفية إلى لجنة تحكيم المحور الرئيسي المختص؛ فلا يصل ملف إلى الحكّام قبل هذه الإحالة.',
    ],
  },
  {
    key: 'judging',
    title: 'التحكيم المستقل',
    summary: 'لجنة تحكيم مستقلة لكل محور رئيسي، من 3 أو 5 محكّمين.',
    points: [
      'تدرس اللجان الملفات المحالة إليها وتقيّمها وفق معايير التقييم المعلنة، وتقترح مستويات التكريم أو الحجب.',
      'لجنة النزاهة والتظلمات تتحقق من منع تضارب المصالح وتبتّ في الاعتراضات الإجرائية.',
      'يعتمد مجلس الأمناء النتائج ويصادق عليها إجرائياً، دون التدخل في التقييم الفني.',
    ],
  },
  {
    key: 'recognition',
    title: 'أسبوع الجائزة والتكريم',
    summary: 'فعاليات الجائزة وإعلان الفائزين وتكريم الأثر.',
    points: [
      'إقامة معارض فنية وورش عمل وعروض والحفل الختامي.',
      'إعلان الفائزين والتكريم المالي والتطويري خلال أسبوع الجائزة.',
      'ثم ربط المكرَّمين بفرص التمكين والتدريب والتشبيك.',
    ],
  },
]

export const eligibility = {
  // Terms: «شروط التقديم» and «شروط ومعايير الترشح»; field-verification readiness removed (owner decision).
  conditions: generalConditions('ar'),
  // Terms: «الفئات المؤهلة».
  applicants: publicConditions.ar.general[1].text,
  // Terms: «إرفاق وثائق وأدلة الأثر», with the platform's actual documentation stage.
  evidence: {
    intro: 'يُبنى ملف الترشح على أدلة تثبت اكتمال العمل وأثره: الملفات التوثيقية، والتقارير الإجرائية، والصور، ومقاطع الفيديو، والشهادات.',
    items: [
      'وثيقة شرح العمل: مطلوبة، ملف واحد بصيغة PDF أو DOC أو DOCX.',
      'وثائق داعمة إضافية: اختيارية، حتى 20 ملفاً بالصيغ نفسها.',
      'فيديوهات توثيق العمل: اختيارية، حتى 10 مقاطع بصيغة MP4 أو WebM.',
      'روابط توثيق إضافية: اختيارية، حتى 20 رابطاً.',
    ],
  },
  // Terms: «شرط منع تضارب المصالح الصارم».
  conflict: generalConditions('ar')[4].text,
  // Terms: «معايير التقديم»; the impact criterion is verified through the evidence (no field visits).

  // Owner decisions: the shared conditions apply to every subcategory.
  shared: 'تُطبق الشروط العامة على الفئات الست، إضافة إلى شروط كل فئة الواردة أدناه.',
}

// The owner's presentation (2026-11-11): «لماذا الجائزة؟».
export const why = [
  { title: 'اكتشاف المبادرات «المنسيّة»', text: 'التي تعمل بصمت في الميدان' },
  { title: 'صون الذاكرة الجماعية', text: 'وحماية التراث الثقافي السوري المادي واللامادي' },
  { title: 'بناء منصة وطنية موثوقة', text: 'تضمن استدامة أثر المبادرات وتحويل العمل الإغاثي إلى تنمية مستدامة' },
  { title: 'ترسيخ الوفاء', text: 'لمسيرة العطاء الإنساني والتنموي للدكتور عبد القادر سنكري' },
]

// The owner's presentation: «الغاية والأهداف الاستراتيجية».
export const purpose = {
  grand: 'بوفاءٍ للأثر الذي لا يغيب... نمكّن المبادرة لنحمي ذاكرة الوطن ونبني إنسان المستقبل',
  objectives: [
    'ترسيخ اسم الجائزة كمنصة سنوية تحظى بثقة المجتمع والشركاء',
    'تكريم المبادرات المنسيّة وتوسيع دائرة الاعتراف المجتمعي بها',
    'بناء أرشيف معرفي ورقمي يوثق قصص الأثر والتراث السوري',
    'تمكين المبادرات الفائزة عبر التدريب والتشبيك والمتابعة البعدية',
    'رفع كفاءة وحوكمة العمل الأهلي والثقافي',
  ],
}

// The owner's presentation: «منظومة الحوكمة» (four stations, in order).
export type GovernanceStation = { key: 'eligibility' | 'committees' | 'field' | 'board'; title: string; text: string }

export const governance: GovernanceStation[] = [
  { key: 'eligibility', title: 'فحص الأهلية المبدئي', text: 'تدقيق الشروط الفنية والقانونية للملفات.' },
  { key: 'committees', title: 'لجان تحكيم مستقلة', text: '3 إلى 5 خبراء متخصصين لكل محور.' },
  { key: 'field', title: 'التحقق الميداني (العناية الواجبة)', text: 'زيارات واقعية لمطابقة التقارير بالواقع وسؤال المستفيدين.' },
  { key: 'board', title: 'مجلس الأمناء', text: 'المصادقة الإجرائية واعتماد النتائج.' },
]

// The owner's presentation: «الدورة التشغيلية ومسار الجائزة».
export const cycle = [
  { title: 'المرحلة الأولى', points: ['إطلاق المنصة الإلكترونية', 'فحص الأهلية'] },
  { title: 'المرحلة الثانية', points: ['التقييم المستقل', 'التحقق الميداني'] },
  { title: 'المرحلة الثالثة', points: ['فعاليات أسبوع الجائزة', 'حفل الختام', 'المتابعة البعدية'] },
]

/** For enquiries and cooperation (supplied by the owner, 2026-11-11). The number opens a WhatsApp chat. */
export const contact = {
  email: 'award@onder1.org',
  phone: '+90 555 109 05 89',
  whatsapp: 'https://wa.me/905551090589',
}

export const closing = {
  // Guide p. 16.
  statement: 'إنها جائزة تحتفي بمن يصنع أثراً... ويبقيه حاضراً في الذاكرة والمجتمع',
}

/** The Arabic copy as one value; siteEn.ts has the same shape in English (use it through useSite). */
export const siteAr = { award, organizations, pages, partners, why, purpose, governance, cycle, contact, hero, domains, about, legacy, confirmedSchedule, journey, eligibility, closing }

export type SiteContent = typeof siteAr
