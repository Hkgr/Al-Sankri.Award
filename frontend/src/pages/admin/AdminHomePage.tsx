import type { User } from '../../auth/useAuth'
import { Link } from '../../lib/router'
import { usePageTitle } from '../auth/usePageTitle'
import type { AdminCycle } from './adminApi'
import { cycleLabel, CYCLE_STATUS_LABELS } from './adminApi'
import { useLocale } from '../../i18n/useLocale'

/** A short welcome with the available modules. */
export function AdminHomePage({ user, cycle }: { user: User; cycle: AdminCycle | null }) {
  const { t } = useLocale()
  usePageTitle(t('لوحة الإدارة'))
  const modules = [
    {
      href: '/admin/users',
      title: t('المستخدمون'),
      text: t('كل الحسابات المسجّلة للعرض فقط: الأدوار الحالية، وحالة الحساب، وتقدّم الترشح في الدورة المختارة.'),
      allowed: user.admin_permissions.includes('users.view_registered'),
    },
    {
      href: '/admin/applications',
      title: t('ملفات الترشح'),
      text: t('المشاركات المرسلة في الدورة: البحث والتصفية، وعرض كل ملف كما أُرسل مع وثائقه وفيديوهاته.'),
      allowed: user.admin_permissions.includes('applications.view_submitted'),
    },
    {
      href: '/admin/committees',
      title: t('لجان الحكام'),
      text: t('لجنة تحكيم لكل مجال رئيسي: إضافة الحكام وإنهاء العضويات واعتماد اللجنة بثلاثة أو خمسة حكام.'),
      allowed: user.admin_permissions.includes('committees.manage_judging'),
    },
    {
      href: '/admin/schedule',
      title: t('المواعيد'),
      text: t('فترة الترشح والتسجيل وفترة التحكيم للدورة المختارة، مع إمكانية تغيير تواريخها.'),
      allowed: user.admin_permissions.includes('schedules.manage'),
    },
    {
      href: '/admin/log',
      title: t('السجل'),
      text: t('سجل الحركات المسجّلة في المنصة، وملخّصات منقّحة للأخطاء التقنية في سجلات التطبيق.'),
      allowed: user.admin_permissions.includes('audit.view') || user.admin_permissions.includes('logs.view_system'),
    },
  ]

  return (
    <div className="admin-page">
      <header className="admin-page__header">
        <h1 className="admin-page__title">
          {t('أهلاً،')} <bdi>{user.name}</bdi>
        </h1>
        <p className="admin-page__intro">
          {cycle ? (
            <>
              {t('الدورة المختارة:')} <strong>{cycleLabel(cycle)}</strong> ({CYCLE_STATUS_LABELS[cycle.status] ?? cycle.status}). {t('يمكنك تغييرها من أعلى الصفحة.')}
            </>
          ) : (
            t('لا توجد دورة جائزة مُعدّة بعد.')
          )}
        </p>
      </header>
      <ul className="admin-modules">
        {modules
          .filter((module) => module.allowed)
          .map((module) => (
            <li key={module.href} className="admin-module">
              <h2 className="admin-module__title">
                <Link href={module.href}>{module.title}</Link>
              </h2>
              <p>{module.text}</p>
            </li>
          ))}
      </ul>
    </div>
  )
}
