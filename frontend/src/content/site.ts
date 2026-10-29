/*
 * Public website copy and data.
 *
 * Award information comes from the supplied documents in docs/:
 *   plan    خطة جائزة الدكتور عبد القادر سنكري للثقافة والتراث والتنمية المجتمعية.docx
 *   terms   جائزة الدكتور عبد القادر سنكري شروط التقديم.docx
 *   launch  التصور التنفيذي لحفل الإطلاق الرسمي.docx
 *   guide   جائزة السنكري visual identity.pdf (physical page numbers)
 * and the owner's decisions of 2026-10-29, which override the documents:
 * the official name and slogan, three main domains with seven
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

export const award = {
  name: 'جائزة الدكتور عبد القادر سنكري للثقافة والتراث والإبداع',
  shortName: 'جائزة الدكتور عبد القادر سنكري',
  slogan: 'وفاءٌ للأثر... تمكينٌ للمبادرة',
}

/** Organisations named in public prose; each is rendered as an external link (OrgLink). */
export const organizations = {
  rowad: { name: 'مؤسسة الروّاد للتعاون والتنمية', url: 'https://www.alrowadngo.sy/' },
  hand: { name: 'منظمة HAND', url: 'https://www.hand.ngo/' },
} as const

export type OrganizationKey = keyof typeof organizations

/** Prose that may name an organisation: plain strings and organisation links, in order. */
export type RichText = (string | { org: OrganizationKey; label?: string })[]

export type SitePage = { href: string; label: string; description: string }

/** The four main pages, in navigation order. */
export const pages: SitePage[] = [
  {
    href: '/',
    label: 'الرئيسية',
    description:
      'جائزة الدكتور عبد القادر سنكري للثقافة والتراث والإبداع: جائزة سنوية تكتشف المبادرات والأفراد والفرق التي تصنع أثراً حقيقياً موثقاً في سوريا وتكرّمها، وتنفّذها مؤسسة الروّاد للتعاون والتنمية.',
  },
  {
    href: '/about',
    label: 'عن الجائزة',
    description: 'غاية الجائزة وقيمها، وتركيزها على الأثر الموثق، والجهة المنفذة، وصلتها بمسيرة الدكتور عبد القادر سنكري في العطاء الإنساني والتنموي.',
  },
  {
    href: '/journey',
    label: 'مسيرة الجائزة',
    description: 'مراحل الدورة الأولى للجائزة: الإعداد، والإطلاق واستقبال ملفات الترشح، والتدقيق الإداري، والتحكيم المستقل، وأسبوع الجائزة المخطط له.',
  },
  {
    href: '/eligibility',
    label: 'شروط التقدم',
    description: 'شروط التقدم المشتركة لمحاور الجائزة الثلاثة، والوثائق وأدلة الأثر المطلوبة، وقواعد منع تضارب المصالح، ومبادئ التقييم.',
  },
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

export type Subcategory = { label: string; scope: string }

export type MainDomain = {
  slug: 'culture-and-arts' | 'heritage' | 'creativity'
  label: string
  /** The approved award artwork. Creativity uses the former Volunteer Work shape (owner decision). */
  symbol: string
  /** What the domain celebrates (guide pp. 10–15). */
  intro: string
  /** Number of awards in the domain (plan and terms: «محاور الجائزة»). */
  awards: number
  subcategories: Subcategory[]
}

// Three main domains and seven subcategories (owner decision; plan and terms tables).
export const domains: MainDomain[] = [
  {
    slug: 'culture-and-arts',
    label: 'الثقافة والفنون',
    symbol: domainCultureArts,
    intro: 'يحتفي بالقراءة والفنون والإنتاج الثقافي بوصفها أثراً يُكتب ويُشارك.',
    awards: 2,
    subcategories: [
      { label: 'الثقافة', scope: 'أعمال القراءة والمعرفة والإنتاج الثقافي التي تصنع أثراً يُكتب ويُشارك في المجتمع.' },
      { label: 'الفنون', scope: 'الأعمال الفنية والإبداعية التي تقدّم أثراً ثقافياً ملموساً يتجاوز صاحبه إلى جمهوره ومجتمعه.' },
    ],
  },
  {
    slug: 'heritage',
    label: 'التراث',
    symbol: domainHeritage,
    intro: 'يحتفي بحفظ التراث المادي واللامادي، وتوثيق الحرف والقصص والعناصر الثقافية بوصفها أثراً يُصان ويُشارك.',
    awards: 2,
    subcategories: [
      { label: 'التراث المادي', scope: 'صون الشواهد الملموسة من الذاكرة الجماعية وتوثيقها: الحرف والمواقع والمقتنيات وما يحمل الهوية من موروث مادي.' },
      { label: 'التراث اللامادي', scope: 'حفظ الموروث الحي ونقله: القصص والتراث الشفهي والممارسات والمعارف المتوارثة.' },
    ],
  },
  {
    slug: 'creativity',
    label: 'الإبداع',
    symbol: domainVolunteer,
    intro: 'يحتفي بالمبادرات التي تجمع الناس حول قيمة مشتركة، وبالأفكار التي تتحول إلى فرص، وبالدقة والتحسين المستمر بوصفهما أثراً يُصنع بالفعل.',
    awards: 3,
    subcategories: [
      { label: 'المسؤولية المجتمعية', scope: 'المبادرات التي تجمع الأفراد والجهات حول قيمة مشتركة وتخدم المجتمع.' },
      { label: 'ريادة الأعمال', scope: 'انطلاق الفكرة وتحوّلها إلى فرصة، والنمو والتوسع اللذان يدعمان المشروع في رحلته نحو الاستمرار وصناعة القيمة.' },
      { label: 'الجودة', scope: 'الدقة والتحسين المستمر، والجودة بوصفها معياراً يحمي قيمة العمل ويؤكد استحقاق أثره.' },
    ],
  },
]

export function domainBySlug(slug: string): MainDomain | undefined {
  return domains.find((domain) => domain.slug === slug)
}

export const about = {
  // Terms: «الفلسفة والغاية الأساسية».
  philosophy:
    'تبرز الجائزة قيمةً أخلاقية تجسّد الوفاء لمسيرة الدكتور عبد القادر سنكري في العطاء الإنساني والتنموي، وتهدف إلى تأسيس منصة سنوية سورية موثوقة تكتشف المبادرات والأفراد والفرق المغمورة التي تعمل بصمت وتصنع أثراً حقيقياً، وتكرّمها.',
  // Guide p. 16 (closing page).
  statement: 'لا تحتفي الجائزة بالإنجاز بوصفه نتيجة فحسب، بل بالقيمة التي يصنعها، وبالذاكرة التي يحفظها، وبالمبادرة التي تفتح طريقاً لغيرها.',
  // Plan and terms: the award's aims.
  aims: [
    { title: 'اكتشاف الأثر المغمور', text: 'اكتشاف المبادرات والأفراد والفرق المغمورة التي تصنع أثراً حقيقياً وموثقاً، وتكريمها.' },
    { title: 'حماية الذاكرة والتراث', text: 'حماية الذاكرة الجماعية والتراث، بوصفهما أثراً يُصان ويُشارك.' },
    { title: 'التمكين بعد التكريم', text: 'ربط المكرَّمين بفرص التمكين والتدريب والتشبيك، لضمان استدامة التنمية المجتمعية.' },
  ],
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

/** The confirmed schedule, shown as text when the server has not published one (the countdown never runs on it). */
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
    summary: 'تهيئة الجائزة قبل فتح باب الترشح.',
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
    summary: 'مرحلة مخطط لها، يُعلن موعدها لاحقاً.',
    points: [
      'فعاليات تفاعلية مخطط لها: معارض فنية، وورش عمل، وعروض، والحفل الختامي.',
      'إعلان الفائزين والتكريم المالي والتطويري خلال أسبوع الجائزة.',
      'ثم ربط المكرَّمين بفرص التمكين والتدريب والتشبيك.',
    ],
  },
]

export const eligibility = {
  // Terms: «شروط التقديم» and «شروط ومعايير الترشح»; field-verification readiness removed (owner decision).
  conditions: [
    { title: 'النطاق الجغرافي', text: 'أن يكون العمل، مبادرةً أو مشروعاً أو نشاطاً، قائماً ومنفّذاً داخل سوريا، وأن يقع مقرّه ونشاطه ضمن النطاق الجغرافي السوري في هذه الدورة.' },
    { title: 'أثر موثّق', text: 'أن يكون للعمل أثر ملموس وموثق في الواقع، وألّا يكون فكرة نظرية أو مقترحاً لم يبدأ تنفيذه.' },
    { title: 'مطابقة أحد المحاور', text: 'أن يطابق العمل أحد المحاور الرئيسية الثلاثة المعلنة، وأن يُقدَّم ضمن فئة من فئاتها.' },
    { title: 'اكتمال الملف والأهلية', text: 'استيفاء البيانات والوثائق الداعمة وأدلة الأثر المطلوبة، وخلو الملف من أي مخالفة للشروط الفنية أو القانونية.' },
    { title: 'النزاهة والشفافية', text: 'الالتزام بالأخلاقيات والنزاهة والشفافية في كل البيانات والتقارير المقدَّمة.' },
    { title: 'توثيق قصة الأثر', text: 'الموافقة على توثيق قصة الأثر إعلامياً وإدراجها ضمن الأرشيف المعرفي والقاعدة البيانية الرقمية المفتوحة للمؤسسة.' },
    { title: 'المشاركة في أسبوع الجائزة', text: 'الالتزام بالمشاركة في فعاليات أسبوع الجائزة المخطط لها (المعرض وورش العمل والمنتدى) في حال التأهل للتصفيات النهائية.' },
  ],
  // Terms: «الفئات المؤهلة».
  applicants: 'يُتاح التقديم للأفراد والفرق والمبادرات والمؤسسات الرسمية والمنظمات. وعند التسجيل في المنصة يُقدَّم الملف باسم فرد أو باسم مؤسسة.',
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
  conflict: [
    'يُحظر تماماً على أعضاء مجلس الأمناء، ولجان التحكيم، ولجنة النزاهة، وموظفي إدارة الجائزة في ',
    { org: 'rowad', label: 'مؤسسة الروّاد' },
    ' الترشحُ للجائزة، أو التدخل والتأثير في أي ملفات ترشيح ذات صلة بهم، ضماناً للنزاهة والحياد التام.',
  ] satisfies RichText,
  // Terms: «معايير التقديم»; the impact criterion is verified through the evidence (no field visits).
  criteria: [
    { title: 'الأصالة والابتكار', text: 'تقديم حلول ريادية أو مجتمعية مبتكرة تعالج احتياجات محلية حقيقية.' },
    { title: 'مصداقية الأثر المجتمعي وعمقه', text: 'حجم التغيير الإيجابي الملموس الذي أحدثه العمل في حياة المستفيدين والمجتمع، وقابلية هذا الأثر للتحقق من خلال الأدلة المقدَّمة.' },
    { title: 'الاستدامة وقابلية التطوير', text: 'قدرة العمل على الاستمرار والنمو الذاتي بعد مرحلة التكريم.' },
    { title: 'الحوكمة وإدارة العمليات', text: 'وضوح نماذج الإدارة والتوثيق، والشفافية المالية والإجرائية، وآليات التعامل مع المستفيدين.' },
    { title: 'حفظ التراث والهوية', text: 'مدى إسهام العمل في صون الذاكرة الجمعية والتراث الثقافي، المادي أو اللامادي.' },
  ],
  // Owner decisions: the shared conditions apply to every subcategory.
  shared: 'تنطبق الشروط ومعايير التقييم نفسها على المحاور الثلاثة وفئاتها السبع؛ ويبيّن تعريف كل فئة نطاقها فقط، دون شروط إضافية خاصة بها.',
}

export const closing = {
  // Guide p. 16.
  statement: 'إنها جائزة تحتفي بمن يصنع أثراً... ويبقيه حاضراً في الذاكرة والمجتمع',
}
