import type { Dict } from '../types.js'

export const ar: Dict = {
  'roles.current': 'الأدوار السارية',
  'roles.allCycles': 'الدور الساري عبر جميع الدورات',
  'roles.default': 'المترشح هو الدور الافتراضي للحساب عندما لا يوجد تكليف آخر سارٍ. متطلبات التحقق والتقديم تبقى مستقلة عن هذا التصنيف.',
  'roles.other': 'يبقى دور المترشح غير متاح أثناء أي تكليف آخر سارٍ، ويعود بعد انتهاء جميع هذه التكليفات. تبقى بيانات الترشح السابقة محفوظة.',
  'candidate.refused': 'حسابك مكلّف بدور آخر سارٍ، فلا يمكنه استخدام لوحة المترشح حالياً. يعود دور المترشح بعد انتهاء جميع التكليفات الأخرى.',
  'candidate.judgeBlocked': 'حسابك مكلّف بالتحكيم حالياً، فلا يمكنه استخدام لوحة المترشح أو تقديم ملف ترشح. يعود دور المترشح بعد انتهاء جميع التكليفات الأخرى.',
}

export const en: Dict = {
  'roles.current': 'Current roles',
  'roles.allCycles': 'Current role across all cycles',
  'roles.default': 'Candidate is the default role when the account has no other current assignment. Verification and application requirements remain separate from this classification.',
  'roles.other': 'The candidate role is unavailable during any other current assignment and returns once all such assignments end. Previous nomination data remains saved.',
  'candidate.refused': 'Your account has another current role and cannot use the candidate dashboard. The candidate role returns once all other assignments end.',
  'candidate.judgeBlocked': 'Your account has a current judging assignment and cannot use the candidate dashboard or submit an application. The candidate role returns once all other assignments end.',
  'حسابك مكلّف بالتحكيم حالياً، فلا يمكنه استخدام لوحة المترشح. استخدم بوابة الحكام. يعود دور المترشح بعد انتهاء جميع التكليفات الأخرى.': 'Your account has a current judging assignment and cannot use the candidate dashboard. Use the judge portal. The candidate role returns once all other assignments end.',
  'حسابك مكلّف بدور آخر سارٍ، فلا يمكنه استخدام مسارات المترشح حالياً. يعود دور المترشح بعد انتهاء جميع التكليفات الأخرى.': 'Your account has another current role and cannot use candidate routes. The candidate role returns once all other assignments end.',
}
