/**
 * Messages for the short outcome codes the backend puts in the query string
 * after redirects (Google sign-in, linking, verification, expired sessions).
 */

type Notice = { tone: 'error' | 'success' | 'info'; text: string }

const googleSignIn: Record<string, Notice> = {
  unavailable: { tone: 'info', text: 'تسجيل الدخول عبر Google غير متاح مؤقتاً. يمكنك استخدام البريد الإلكتروني وكلمة المرور.' },
  cancelled: { tone: 'info', text: 'أُلغي تسجيل الدخول عبر Google. يمكنك المحاولة مجدداً أو استخدام البريد الإلكتروني.' },
  invalid_state: { tone: 'error', text: 'انتهت صلاحية محاولة الدخول عبر Google أو لم تكتمل. أعد المحاولة من هذه الصفحة.' },
  failed: { tone: 'error', text: 'تعذّر إكمال تسجيل الدخول عبر Google. أعد المحاولة بعد قليل.' },
  throttled: { tone: 'error', text: 'محاولات كثيرة. انتظر دقيقة ثم أعد المحاولة.' },
  account_exists: {
    tone: 'info',
    text: 'يوجد حساب مسجّل بهذا البريد الإلكتروني. سجّل الدخول بكلمة المرور، ثم اربط حساب Google من صفحة «حسابي» إن أردت.',
  },
  disabled: { tone: 'error', text: 'هذا الحساب موقوف. لا يمكن استخدامه لتسجيل الدخول.' },
}

const googleLink: Record<string, Notice> = {
  linked: { tone: 'success', text: 'رُبط حساب Google بحسابك. يمكنك الآن تسجيل الدخول به أيضاً.' },
  already_linked: { tone: 'info', text: 'حساب Google هذا مرتبط بحسابك مسبقاً.' },
  owned_by_other: { tone: 'error', text: 'حساب Google هذا مرتبط بحساب آخر في الموقع، فلم يُربط بحسابك.' },
  user_has_other: { tone: 'error', text: 'حسابك مرتبط بحساب Google آخر مسبقاً.' },
  link_expired: { tone: 'error', text: 'انتهت مهلة طلب الربط. أكّد كلمة المرور وأعد المحاولة.' },
  unavailable: { tone: 'info', text: 'ربط حساب Google غير متاح حالياً.' },
  cancelled: { tone: 'info', text: 'أُلغي ربط حساب Google.' },
  invalid_state: { tone: 'error', text: 'انتهت صلاحية محاولة الربط أو لم تكتمل. أعد المحاولة.' },
  failed: { tone: 'error', text: 'تعذّر إكمال ربط حساب Google. أعد المحاولة بعد قليل.' },
  throttled: { tone: 'error', text: 'محاولات كثيرة. انتظر دقيقة ثم أعد المحاولة.' },
}

export function loginNotice(params: URLSearchParams): Notice | null {
  const google = params.get('google')
  if (google && googleSignIn[google]) return googleSignIn[google]
  if (params.get('account') === 'disabled') return googleSignIn.disabled
  if (params.get('session') === 'expired') return { tone: 'info', text: 'انتهت جلستك. سجّل الدخول مرة أخرى للمتابعة.' }
  if (params.get('reset') === 'success') return { tone: 'success', text: 'تم تعيين كلمة المرور الجديدة. سجّل الدخول بها الآن.' }
  return null
}

export function accountNotice(params: URLSearchParams): Notice | null {
  const google = params.get('google')
  return google && googleLink[google] ? googleLink[google] : null
}

export const verificationResults: Record<string, Notice> = {
  verified: { tone: 'success', text: 'تم تأكيد بريدك الإلكتروني. شكراً لك.' },
  already: { tone: 'info', text: 'بريدك الإلكتروني مؤكَّد مسبقاً.' },
  invalid: { tone: 'error', text: 'رابط التأكيد غير صالح أو انتهت صلاحيته. اطلب رابطاً جديداً من صفحة «حسابي».' },
  mismatch: { tone: 'error', text: 'هذا الرابط لحساب آخر غير الحساب المسجّل دخوله الآن. سجّل الخروج ثم افتح الرابط مجدداً.' },
}
