/*
 * Homepage copy and data.
 *
 * Award information comes from the supplied documents in docs/:
 *   plan    خطة جائزة الدكتور عبد القادر سنكري ... .docx
 *   launch  التصور التنفيذي لحفل الإطلاق الرسمي.docx
 *   guide   جائزة السنكري visual identity.pdf (physical page numbers)
 * Biographical statements come from HAND Organization's published pages and
 * are listed with their sources in docs/design/photo-provenance.md.
 * Nothing here is invented: no dates, figures, quotations or endorsements.
 */

import domainCultureArts from '../assets/brand/domain-culture-and-arts.svg'
import domainEntrepreneurship from '../assets/brand/domain-entrepreneurship-and-innovation.svg'
import domainQuality from '../assets/brand/domain-quality.svg'
import domainSocialResponsibility from '../assets/brand/domain-social-responsibility.svg'
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
  name: 'جائزة الدكتور عبد القادر سنكري للثقافة والتراث والتنمية المجتمعية',
  slogan: 'وفاءٌ للأثر... وتمكينٌ للمبادرة',
  organization: 'مؤسسة الروّاد للتعاون والتنمية',
  // Verified: the organisation's own site (registration and contact pages).
  organizationUrl: 'https://www.alrowadngo.sy/',
}

/** In-page destinations. Every href points at a section on this page. */
export const sectionLinks = [
  { href: '#about', label: 'عن الجائزة' },
  { href: '#legacy', label: 'سيرة العطاء' },
  { href: '#domains', label: 'المحاور' },
  { href: '#journey', label: 'مسار الجائزة' },
]

export const photoCredit = {
  label: 'منظمة HAND',
  aboutUrl: 'https://www.hand.ngo/about-us',
  visitUrl:
    'https://www.hand.ngo/blog/news-reports-1/in-the-historic-surroundings-of-the-umayyad-mosque-in-damascus-76',
}

export const hero = {
  // Guide p. 7 and plan (implementing organisation).
  lead: 'جائزة سنوية ذات طابع ثقافي وتنموي ومجتمعي، تحتفي بالأفراد والفرق والمؤسسات التي تصنع أثراً موثقاً، وتنفّذها مؤسسة الروّاد للتعاون والتنمية.',
  portrait: {
    sources: [
      { srcSet: `${portrait400} 400w, ${portrait640} 640w, ${portrait800} 800w`, type: 'image/webp' },
    ] satisfies PhotoSource[],
    fallback: portrait800Jpg,
    width: 800,
    height: 790,
    alt: 'صورة شخصية للدكتور عبد القادر سنكري مبتسماً، يرتدي الغترة البيضاء والعقال ونظارة طبية.',
    caption: 'الدكتور عبد القادر سنكري',
  },
}

export const purpose = {
  // Guide p. 16 (closing page).
  statement:
    'لا تحتفي الجائزة بالإنجاز بوصفه نتيجة فحسب، بل بالقيمة التي يصنعها، وبالذاكرة التي يحفظها، وبالمبادرة التي تفتح طريقاً لغيرها.',
  // Plan: «الغاية الأساسية».
  aims: [
    {
      title: 'اكتشاف الأثر المغمور',
      text: 'اكتشاف وتكريم المبادرات والأفراد والفرق المغمورة التي تصنع أثراً حقيقياً وموثقاً.',
    },
    {
      title: 'حماية الذاكرة والتراث',
      text: 'حماية الذاكرة الجماعية والتراث، بوصفهما أثراً يُصان ويُشارك.',
    },
    {
      title: 'تمكين المبدعين',
      text: 'تمكين المبدعين وأصحاب المبادرات لضمان استدامة التنمية.',
    },
  ],
}

export const legacy = {
  // HAND, "USD 40 Million Donation by Dr. Sankari…" (20 Dec 2025), and
  // "In the historic surroundings of the Umayyad Mosque…" (11 May 2026).
  paragraphs: [
    'غادر الدكتور عبد القادر سنكري مدينة حلب عام 1970 لدراسة الصيدلة في الإسكندرية والقاهرة، ثم بدأ مسيرته المهنية محاضراً في جامعة الإمارات العربية المتحدة في العين. وهو رجل أعمال إماراتي من أصل سوري، أسّس مؤسسة الروّاد للتعاون والتنمية ومنظمة HAND ويرأسهما، ومن خلالهما نُفّذت مبادرات إنسانية وتنموية في التعليم والإغاثة والتمكين الاقتصادي وبناء القدرات.',
    'ومن بين ما نفّذته المؤسسات التي يرأسها إعادة تأهيل الجامع الأموي في دمشق بما يحفظ قيمته التاريخية والدينية. وقد عُرف بدعمه الطويل للمبادرات الإنسانية والخيرية في سوريا، مع حرصه في كثير من الأحيان على أن يبقى عطاؤه بعيداً عن الأضواء.',
  ],
  // Connects the documented facts above to the award's documented purpose.
  bridge:
    'ومن هذا المعنى تنطلق الجائزة التي تحمل اسمه: أن يُرى الأثر الحقيقي، وأن يُكرَّم أصحابه وإن عملوا بعيداً عن الأضواء.',
  photo: {
    sources: [
      {
        media: '(max-width: 47.99rem)',
        srcSet: `${visitNarrow480} 480w, ${visitNarrow912} 912w`,
        type: 'image/webp',
        width: 912,
        height: 1110,
      },
      {
        media: '(max-width: 47.99rem)',
        srcSet: `${visitNarrow912Jpg} 912w`,
        width: 912,
        height: 1110,
      },
      { srcSet: `${visit960} 960w, ${visit1600} 1600w`, type: 'image/webp' },
    ] satisfies PhotoSource[],
    fallback: visit1600Jpg,
    width: 1600,
    height: 1017,
    alt: 'الدكتور عبد القادر سنكري بالزي الإماراتي يقف مع مرافقيه في قاعة من قاعات الجامع الأموي، تعلوهم كتابة قرآنية بالفسيفساء.',
    caption:
      'خلال زيارته الجامع الأموي في دمشق في أيار/مايو 2026، وهي أول زيارة له إلى دمشق منذ عام 2011، برفقة عدد من المسؤولين وممثلي المؤسستين.',
  },
}

export type Domain = {
  key: string
  label: string
  symbol: string
  /** What the domain celebrates (guide pp. 10–15, second sentence). */
  text: string
  /** What the symbol depicts (guide pp. 10–15, first sentence). */
  symbolMeaning: string
}

// Order as on guide pp. 6 and 9 (right to left).
export const domains: Domain[] = [
  {
    key: 'culture-and-arts',
    label: 'الثقافة والفنون',
    symbol: domainCultureArts,
    text: 'يحتفي بالقراءة والفنون والإنتاج الثقافي بوصفها أثراً يُكتب ويُشارك.',
    symbolMeaning: 'دفتر مفتوح يتوسطه قلم ذهبي، يرمز إلى المعرفة والكتابة والإبداع.',
  },
  {
    key: 'tangible-and-intangible-heritage',
    label: 'التراث المادي واللامادي',
    symbol: domainHeritage,
    text: 'يحتفي بحفظ التراث المادي واللامادي، وتوثيق الحرف والقصص والعناصر الثقافية بوصفها أثراً يُصان ويُشارك.',
    symbolMeaning: 'قوس معماري يبدو كباب تراثي، يرمز إلى الذاكرة والهوية والموروث الحي.',
  },
  {
    key: 'volunteer-work',
    label: 'العمل التطوعي',
    symbol: domainVolunteer,
    text: 'يحتفي بالعمل التطوعي والمبادرات التي تقوم على المشاركة وخدمة المجتمع، بوصفها أثراً يُصنع بالفعل لا بالكلام.',
    symbolMeaning: 'يد مرفوعة داخل التكوين، ترمز إلى العطاء والمساندة والفعل الإنساني المباشر.',
  },
  {
    key: 'social-responsibility',
    label: 'المسؤولية المجتمعية',
    symbol: domainSocialResponsibility,
    text: 'يحتفي بالمسؤولية المجتمعية والمبادرات التي تجمع الأفراد والجهات حول قيمة مشتركة.',
    symbolMeaning: 'ثلاث نقاط ذهبية داخل التكوين، ترمز إلى المجتمع والشراكة وتكامل الأطراف.',
  },
  {
    key: 'entrepreneurship-and-innovation',
    label: 'ريادة الأعمال والابتكار',
    symbol: domainEntrepreneurship,
    text: 'يحتفي بلحظة انطلاق الفكرة وتحوّلها إلى فرصة، وبالنمو والتوسع اللذين يدعمان المشروع في رحلته نحو الاستمرار وصناعة القيمة.',
    symbolMeaning: 'نقطة ذهبية في أعلى التكوين وخطوط صاعدة من اليمين واليسار، كجناحي النمو والتوسع.',
  },
]

// Plan: «الجودة: كمنهج أساسي متقاطع في كافة المحاور». Presented as an
// approach across all domains; this page does not say how it is nominated.
export const quality: Domain & { role: string } = {
  key: 'quality',
  label: 'الجودة',
  symbol: domainQuality,
  role: 'منهج أساسي متقاطع في كافة المحاور',
  text: 'تمثّل الدقة والتحسين المستمر، بوصف الجودة معياراً يحمي قيمة التكريم ويؤكد استحقاق الأثر في كل محور.',
  symbolMeaning: 'علامة صح داخل التكوين، ترمز إلى التأكيد والاعتماد والتحقق من الجودة.',
}

export const journey = {
  // Plan, section 2: «تتكون خطة إطلاق المبادرة من 3 مراحل رئيسية تضمن النزاهة والحوكمة».
  intro: 'تمضي الدورة التشغيلية للجائزة في ثلاث مراحل رئيسية تضمن النزاهة والحوكمة.',
  stages: [
    {
      title: 'التمهيد والإطلاق',
      summary: 'ورش تعريفية، وفتح المنصة، وفحص الأهلية.',
      points: [
        'جلسات تعريفية تساعد المتقدمين والمبادرات المغمورة على فهم معايير الجائزة وتجهيز الأدلة.',
        'بوابة إلكترونية لاستقبال ملفات الترشيح وتدقيق مطابقتها للشروط الفنية والقانونية.',
      ],
    },
    {
      title: 'التحكيم والتحقق',
      summary: 'لجان مستقلة، وزيارات ميدانية، وتصويت.',
      points: [
        'تقييم الملفات عبر لجان تحكيم تخصصية مستقلة تضمن عدم تضارب المصالح.',
        'زيارات ميدانية للتحقق والعناية الواجبة، للتأكد من مصداقية الأثر في الميدان.',
        'تصويت إلكتروني لـ«جائزة الجمهور» تعزيزاً للمشاركة المجتمعية.',
      ],
    },
    {
      title: 'أسبوع الجائزة',
      summary: 'موسم ثقافي: معرض، وورش، وعروض، والحفل الختامي.',
      points: [
        'فعاليات تفاعلية يُعلن خلالها الفائزون ويُكرَّمون تكريماً مالياً وتطويرياً.',
        'ثم مرحلة التمكين البعدي.',
      ],
    },
  ],
}

export const closing = {
  // Guide p. 16.
  statement: 'إنها جائزة تحتفي بمن يصنع أثراً... ويبقيه حاضراً في الذاكرة والمجتمع',
  text: 'تسعى الجائزة إلى ترسيخ معنى التكريم بوصفه مسؤولية ثقافية ومجتمعية، وتشجيع النماذج الملهمة التي تجمع بين الأصالة والإبداع والعطاء والجودة.',
}
