import type { User } from '../../auth/useAuth'
import { Link } from '../../lib/router'
import { usePageTitle } from '../auth/usePageTitle'
import type { AdminCycle } from './adminApi'
import { cycleLabel, CYCLE_STATUS_LABELS } from './adminApi'

/** A short welcome with the available modules. */
export function AdminHomePage({ user, cycle }: { user: User; cycle: AdminCycle | null }) {
  usePageTitle('لوحة الإدارة')
  const modules = [
    {
      href: '/admin/users',
      title: 'المستخدمون',
      text: 'كل الحسابات المسجّلة للعرض فقط: الأدوار الحالية، وحالة الحساب، وتقدّم الترشح في الدورة المختارة.',
      allowed: user.admin_permissions.includes('users.view_registered'),
    },
    {
      href: '/admin/applications',
      title: 'ملفات الترشح',
      text: 'المشاركات المرسلة في الدورة: البحث والتصفية، وعرض كل ملف كما أُرسل مع وثائقه وفيديوهاته.',
      allowed: user.admin_permissions.includes('applications.view_submitted'),
    },
    {
      href: '/admin/committees',
      title: 'لجان الحكام',
      text: 'لجنة تحكيم لكل مجال رئيسي: إضافة الحكام وإنهاء العضويات واعتماد اللجنة بثلاثة أو خمسة حكام.',
      allowed: user.admin_permissions.includes('committees.manage_judging'),
    },
    {
      href: '/admin/log',
      title: 'السجل',
      text: 'سجل الحركات المسجّلة في المنصة، وملخّصات منقّحة للأخطاء التقنية في سجلات التطبيق.',
      allowed: user.admin_permissions.includes('audit.view') || user.admin_permissions.includes('logs.view_system'),
    },
  ]

  return (
    <div className="admin-page">
      <header className="admin-page__header">
        <h1 className="admin-page__title">
          أهلاً، <bdi>{user.name}</bdi>
        </h1>
        <p className="admin-page__intro">
          {cycle ? (
            <>
              الدورة المختارة: <strong>{cycleLabel(cycle)}</strong> ({CYCLE_STATUS_LABELS[cycle.status] ?? cycle.status}). يمكنك تغييرها من أعلى الصفحة.
            </>
          ) : (
            'لا توجد دورة جائزة مُعدّة بعد.'
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
      <p className="admin-page__note">«المواعيد» قيد الإعداد، وستُتاح في مرحلة لاحقة.</p>
    </div>
  )
}
