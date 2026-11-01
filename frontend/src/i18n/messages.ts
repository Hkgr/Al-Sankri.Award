import { ar as authAr, en as authEn } from './catalogs/auth'
import { ar as publicAr, en as publicEn } from './catalogs/public'
import { en as uiAdminEn } from './catalogs/uiAdmin'
import { en as uiCandidateEn } from './catalogs/uiCandidate'
import { en as uiServerEn } from './catalogs/uiServer'
import { en as uiSharedEn } from './catalogs/uiShared'
import type { Dict, Locale } from './types'

export type { Locale }

const baseAr: Dict = {
  'lang.ar': 'العربية',
  'lang.en': 'English',
  'lang.switch': 'اللغة',
  'common.skip': 'انتقل إلى المحتوى الرئيسي',
  'common.retry': 'إعادة المحاولة',
  'common.loading': 'جارٍ التحميل…',
  'common.menu': 'القائمة',
  'common.account': 'حسابي',
  'common.signIn': 'تسجيل',
  'common.logout': 'تسجيل الخروج',
  'common.loggingOut': 'جارٍ تسجيل الخروج…',
  'common.publicSite': 'العودة إلى الموقع العام',
  'common.accountSettings': 'إعدادات الحساب',
  'common.damascusTime': 'بتوقيت دمشق',

  'judge.eyebrow': 'بوابة الحكم',
  'judge.title': 'التحكيم',
  'judge.intro': 'مشاريع محورك. ضع علامة من 100 مع الملاحظات والتوصيات، ثم أرسلها إلى الإدارة.',
  'judge.loading': 'جارٍ تحميل المشاريع…',
  'judge.empty': 'لا توجد مشاريع للتحكيم بعد. تظهر بعد اعتماد لجنة محورك ووصول ملفات الترشح المرسلة.',
  'judge.waiting': 'بانتظار علامتك',
  'judge.sentScore': 'أُرسلت إلى الإدارة · {score} من 100',
  'judge.sentExcluded': 'أُرسلت إلى الإدارة · استبعاد',
  'judge.nav': 'التحكيم',
  'judge.role': 'حكَم',
  'judge.refused': 'هذه الصفحة مخصّصة لحكّام الجائزة، وحسابك ليس حكماً في دورة حالية.',
  'judge.goAccount': 'الانتقال إلى حسابي',
  'judge.windowOpen': 'فترة التحكيم مفتوحة من {from} إلى {to} بتوقيت دمشق.',
  'judge.windowUpcoming': 'فترة التحكيم تبدأ من {from} إلى {to} بتوقيت دمشق. يمكنك رؤية المشاريع الآن، والدخول إليها عند بدء الفترة.',
  'judge.windowClosed': 'انتهت فترة التحكيم ({from} — {to} بتوقيت دمشق). يمكنك رؤية المشاريع دون فتحها.',
  'judge.windowUnknown': 'فترة التحكيم لم تُضبط بعد. يمكنك رؤية المشاريع، ولا يُفتح أي مشروع حتى تُفتح الفترة.',
  'judge.locked': 'يُفتح عند بدء فترة التحكيم',
  'judge.stageClosedTitle': 'فترة التحكيم لم تبدأ بعد',
  'judge.stageClosedBody': 'يمكنك العودة إلى قائمة المشاريع. الدخول إلى تفاصيل المشروع متاح عند بدء فترة التحكيم.',
  'judge.backToList': 'العودة إلى المشاريع',
  'judge.scoreClosed': 'فترة التحكيم غير مفتوحة الآن، فلا تُرسل العلامة في هذا الوقت.',
  'judge.projectTitle': 'تحكيم مشروع',
  'judge.checkingSession': 'جارٍ التحقق من الجلسة…',
  'judge.sections': 'أقسام بوابة الحكم',
  'judge.leaveWarning': 'علامتك لم تُرسل إلى الإدارة بعد. هل تغادر الصفحة؟',
  'judge.error.score': 'ضع العلامة من 100.',
  'judge.error.notes': 'اكتب الملاحظات قبل الإرسال.',
  'judge.error.recommendations': 'اكتب التوصيات قبل الإرسال.',
  'judge.notAssigned': 'لم يُسند إليك هذا المشروع.',
  'judge.loadingProject': 'جارٍ تحميل المشروع…',
  'judge.domain': 'المحور',
  'judge.submittedAt': 'أُرسل {when} بتوقيت دمشق',
  'judge.workData': 'بيانات العمل',
  'judge.workFiles': 'ملفات العمل',
  'judge.noFiles': 'لم يُضف شيء.',
  'judge.extraLinks': 'روابط توثيق إضافية',
  'judge.noLinks': 'لم تُضف روابط.',
  'judge.yourMark': 'علامتك',
  'judge.scoreLabel': 'العلامة من 100',
  'judge.notes': 'الملاحظات',
  'judge.recommendations': 'التوصيات',
  'judge.exclude': 'استبعاد المشارك',
  'judge.excludeHint': 'المشارك مخالف، فلا تُوضع له علامة، ونتيجته استبعاد.',
  'judge.send': 'إرسال إلى الإدارة',
  'judge.sending': 'جارٍ الإرسال…',

  'auth.login': 'تسجيل الدخول',
  'auth.register': 'إنشاء حساب',
  'auth.loginIntro': 'ادخل إلى حسابك في موقع الجائزة.',
  'auth.registerTitle': 'إنشاء حساب',
  'auth.accountMenu': 'قائمة الحساب',
  'auth.notFound': 'الصفحة غير موجودة',

  'candidate.role': 'مترشح',
  'candidate.dashboard': 'لوحة المترشح',
  'candidate.nav': 'أقسام لوحة المترشح',
  'candidate.home': 'الرئيسية',
  'candidate.basic': 'البيانات الأساسية',
  'candidate.nomination': 'فئة الترشح وبيانات العمل',
  'candidate.documentation': 'ملفات العمل وتوثيقه',
  'candidate.review': 'المراجعة والإرسال',
  'candidate.accountChanged': 'تغيّر الحساب المسجّل دخوله في هذا المتصفح، فحدّثنا الصفحة لتعرض الحساب الحالي وبياناته.',
  'candidate.judgeBlocked': 'حسابك مُعيَّن حكَماً في الجائزة، فلا يمكنه استخدام لوحة المترشح أو تقديم ملف ترشح. تغيير الأدوار يتم عبر إدارة الجائزة فقط.',
  'candidate.goJudge': 'الانتقال إلى بوابة الحكام',
  'candidate.refused': 'هذه اللوحة مخصّصة لحسابات المترشحين، وحسابك ليس منها.',

  'admin.role': 'إدارة الجائزة',
  'admin.refused': 'هذه اللوحة مخصّصة لإدارة الجائزة، وحسابك لا يملك صلاحية الوصول إليها.',
  'admin.dashboard': 'لوحة الإدارة',
  'admin.nav': 'أقسام لوحة الإدارة',
  'admin.home': 'الرئيسية',
  'admin.users': 'المستخدمون',
  'admin.applications': 'ملفات الترشح',
  'admin.committees': 'لجان الحكام',
  'admin.schedule': 'المواعيد',
  'admin.log': 'السجل',
  'admin.cycle': 'الدورة',
  'admin.allCycles': 'كل الدورات',
  'admin.noCycles': 'لا توجد دورات بعد',
  'admin.soon': 'قريباً',
}

const baseEn: Dict = {
  'lang.ar': 'العربية',
  'lang.en': 'English',
  'lang.switch': 'Language',
  'common.skip': 'Skip to main content',
  'common.retry': 'Try again',
  'common.loading': 'Loading…',
  'common.menu': 'Menu',
  'common.account': 'My account',
  'common.signIn': 'Sign in',
  'common.logout': 'Sign out',
  'common.loggingOut': 'Signing out…',
  'common.publicSite': 'Back to the public site',
  'common.accountSettings': 'Account settings',
  'common.damascusTime': 'Damascus time',

  'judge.eyebrow': 'Judge portal',
  'judge.title': 'Judging',
  'judge.intro': 'Projects in your domain. Enter a mark out of 100 with notes and recommendations, then send it to the administration.',
  'judge.loading': 'Loading projects…',
  'judge.empty': 'No projects to judge yet. They appear after your committee is approved and submitted applications arrive.',
  'judge.waiting': 'Awaiting your mark',
  'judge.sentScore': 'Sent to administration · {score} out of 100',
  'judge.sentExcluded': 'Sent to administration · Excluded',
  'judge.nav': 'Judging',
  'judge.role': 'Judge',
  'judge.refused': 'This page is for award judges, and your account is not a current judge.',
  'judge.goAccount': 'Go to my account',
  'judge.windowOpen': 'The judging period is open from {from} to {to} (Damascus time).',
  'judge.windowUpcoming': 'The judging period runs from {from} to {to} (Damascus time). You can see the projects now; open them once the period starts.',
  'judge.windowClosed': 'The judging period has ended ({from} — {to}, Damascus time). You can see the projects without opening them.',
  'judge.windowUnknown': 'The judging period has not been set yet. You can see the projects, but none can be opened until the period starts.',
  'judge.locked': 'Opens when the judging period starts',
  'judge.stageClosedTitle': 'The judging period has not started yet',
  'judge.stageClosedBody': 'Return to the project list. Project details open when the judging period starts.',
  'judge.backToList': 'Back to projects',
  'judge.scoreClosed': 'The judging period is not open now, so a mark cannot be sent.',
  'judge.projectTitle': 'Judge a project',
  'judge.checkingSession': 'Checking your session…',
  'judge.sections': 'Judge portal sections',
  'judge.leaveWarning': 'Your mark has not been sent to the administration yet. Leave this page?',
  'judge.error.score': 'Enter the mark out of 100.',
  'judge.error.notes': 'Write your notes before sending.',
  'judge.error.recommendations': 'Write your recommendations before sending.',
  'judge.notAssigned': 'This project is not assigned to you.',
  'judge.loadingProject': 'Loading the project…',
  'judge.domain': 'Domain',
  'judge.submittedAt': 'Submitted {when} (Damascus time)',
  'judge.workData': 'Work details',
  'judge.workFiles': 'Work files',
  'judge.noFiles': 'Nothing added.',
  'judge.extraLinks': 'Additional documentation links',
  'judge.noLinks': 'No links added.',
  'judge.yourMark': 'Your mark',
  'judge.scoreLabel': 'Mark out of 100',
  'judge.notes': 'Notes',
  'judge.recommendations': 'Recommendations',
  'judge.exclude': 'Exclude the participant',
  'judge.excludeHint': 'The participant is in breach, so no mark is given and the result is exclusion.',
  'judge.send': 'Send to administration',
  'judge.sending': 'Sending…',

  'auth.login': 'Sign in',
  'auth.register': 'Create an account',
  'auth.loginIntro': 'Sign in to your account on the award site.',
  'auth.registerTitle': 'Create an account',
  'auth.accountMenu': 'Account menu',
  'auth.notFound': 'Page not found',

  'candidate.role': 'Candidate',
  'candidate.dashboard': 'Candidate dashboard',
  'candidate.nav': 'Candidate dashboard sections',
  'candidate.home': 'Home',
  'candidate.basic': 'Basic information',
  'candidate.nomination': 'Nomination category and work details',
  'candidate.documentation': 'Work files and documentation',
  'candidate.review': 'Review and submit',
  'candidate.accountChanged': 'The signed-in account in this browser changed, so we refreshed the page for the current account.',
  'candidate.judgeBlocked': 'Your account is appointed as a judge, so it cannot use the candidate dashboard or submit an application. Role changes are made only by award administration.',
  'candidate.goJudge': 'Go to the judge portal',
  'candidate.refused': 'This dashboard is for candidate accounts, and yours is not one.',

  'admin.role': 'Award administration',
  'admin.refused': 'This dashboard is for award administration, and your account does not have access.',
  'admin.dashboard': 'Administration',
  'admin.nav': 'Administration sections',
  'admin.home': 'Home',
  'admin.users': 'Users',
  'admin.applications': 'Applications',
  'admin.committees': 'Judge committees',
  'admin.schedule': 'Schedule',
  'admin.log': 'Log',
  'admin.cycle': 'Cycle',
  'admin.allCycles': 'All cycles',
  'admin.noCycles': 'No cycles yet',
  'admin.soon': 'Soon',
}

const catalogs: Record<Locale, Dict> = {
  ar: { ...baseAr, ...publicAr, ...authAr },
  en: { ...baseEn, ...publicEn, ...authEn, ...uiSharedEn, ...uiCandidateEn, ...uiAdminEn, ...uiServerEn },
}

export function translate(locale: Locale, key: string, vars?: Record<string, string | number>): string {
  let text = catalogs[locale][key] ?? catalogs.ar[key] ?? key
  if (vars) {
    for (const [name, value] of Object.entries(vars)) {
      text = text.replaceAll(`{${name}}`, String(value))
    }
  }
  return text
}

/*
 * The language the interface is in right now, for code that runs outside a
 * component (validators, label builders). Components still call useLocale(),
 * so they render again when the language changes.
 */
let currentLocale: Locale = 'ar'

export function setCurrentLocale(locale: Locale): void {
  currentLocale = locale
}

/**
 * A table of Arabic labels that reads back in the current language:
 * STATUS_LABELS[status] is translated each time it is looked up.
 */
export function labels<T extends Record<string, string>>(source: T): T {
  return new Proxy(source, {
    get: (target, key, receiver) => {
      const value = Reflect.get(target, key, receiver)
      return typeof value === 'string' ? tr(value) : value
    },
  })
}

export function getLocale(): Locale {
  return currentLocale
}

export function tr(key: string, vars?: Record<string, string | number>): string {
  return translate(currentLocale, key, vars)
}

let regionNames: Intl.DisplayNames | null = null

/** A country's name in English from its ISO code (the server sends Arabic names). */
function englishCountryName(code: string): string | null {
  try {
    regionNames ??= new Intl.DisplayNames(['en'], { type: 'region' })
    return regionNames.of(code) ?? null
  } catch {
    return null
  }
}

/**
 * The server answers in Arabic. In English, the texts it sends (messages,
 * labels, domain names) are replaced by their English where one is known
 * (exact match), and countries are named from their code. Anything unknown
 * stays as the server wrote it.
 */
export function localizeServerData<T>(value: T): T {
  if (currentLocale === 'ar') return value
  const walk = (item: unknown): unknown => {
    if (typeof item === 'string') return /[\u0600-\u06FF]/.test(item) ? (catalogs.en[item] ?? item) : item
    if (Array.isArray(item)) return item.map(walk)
    if (item && typeof item === 'object') {
      const record = item as Record<string, unknown>
      const entries = Object.entries(record).map(([key, inner]) => [key, walk(inner)] as const)
      const keys = Object.keys(record)
      const isCountry =
        typeof record.code === 'string' && /^[A-Z]{2}$/.test(record.code) && typeof record.name === 'string' && keys.every((key) => ['code', 'name', 'calling_code'].includes(key))
      const result = Object.fromEntries(entries)
      if (isCountry) result.name = englishCountryName(record.code as string) ?? result.name
      return result
    }
    return item
  }
  return walk(value) as T
}

export function localeDir(locale: Locale): 'rtl' | 'ltr' {
  return locale === 'ar' ? 'rtl' : 'ltr'
}
