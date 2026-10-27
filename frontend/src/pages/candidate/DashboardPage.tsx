import { useCallback, useEffect, useState } from 'react'
import type { User } from '../../auth/useAuth'
import { FormAlert, StatusIcon } from '../../components/forms/FormControls'
import { api, errorMessage } from '../../lib/api'
import { Link } from '../../lib/router'
import { useRouter } from '../../lib/useRouter'
import { usePageTitle } from '../auth/usePageTitle'
import { formatDamascusDateTime, PROFILE_TYPE_LABELS } from './candidateApi'
import type { Overview } from './candidateApi'
import { useAccountFailure } from './useCandidateRequests'
import { VerifyEmailNotice } from './VerifyEmailNotice'

/**
 * The candidate's home: a welcome, the stages of the nomination file and
 * their real state from the backend. Only stage 1 exists so far; later
 * stages are announced honestly as not yet available, without inventing
 * their content or dates.
 */
export function DashboardPage({ user }: { user: User }) {
  usePageTitle('لوحة المترشح')
  const { location } = useRouter()
  const handleAccountFailure = useAccountFailure()
  const [overview, setOverview] = useState<Overview | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)
  const params = new URLSearchParams(location.search)

  const load = useCallback(async () => {
    setLoadError(null)
    try {
      setOverview((await api.get<{ data: Overview }>('/api/candidate/overview')).data)
    } catch (error) {
      if (!handleAccountFailure(error)) setLoadError(errorMessage(error))
    }
  }, [handleAccountFailure])

  useEffect(() => {
    // Fetching on mount: the state is set once the request settles.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load()
  }, [load])

  return (
    <div className="candidate-page">
      <header className="candidate-page__header">
        <p className="eyebrow">لوحة المترشح</p>
        <h1 className="candidate-page__title">
          مرحباً، <bdi>{user.name}</bdi>
        </h1>
        <p className="candidate-page__intro">من هنا تستكمل ملف الترشح لجائزة الدكتور عبد القادر سنكري مرحلةً بعد مرحلة.</p>
      </header>

      {params.get('welcome') === '1' && (
        <FormAlert tone={params.get('mail') === 'failed' ? 'info' : 'success'}>
          <p>
            {params.get('mail') === 'failed'
              ? 'أُنشئ حسابك، لكن تعذّر إرسال رسالة تأكيد البريد الإلكتروني الآن. اطلب رابطاً جديداً من الأسفل بعد قليل.'
              : 'أُنشئ حسابك، وأرسلنا رابط تأكيد إلى بريدك الإلكتروني.'}
          </p>
        </FormAlert>
      )}
      {params.get('saved') === 'basic-information' && (
        <FormAlert tone="success">
          <p>حُفظت بياناتك الأساسية، وأصبحت متاحة للعرض فقط. ستُتاح المراحل التالية من ملف الترشح لاحقاً.</p>
        </FormAlert>
      )}

      {!user.email_verified && <VerifyEmailNotice context="dashboard" />}

      <section className="candidate-section" aria-labelledby="stages-title">
        <h2 id="stages-title" className="candidate-section__title">
          مراحل استكمال ملف الترشح
        </h2>

        {loadError ? (
          <FormAlert tone="error">
            <p>{loadError}</p>
            <p>
              <button type="button" className="button button--secondary" onClick={() => void load()}>
                إعادة المحاولة
              </button>
            </p>
          </FormAlert>
        ) : !overview ? (
          <p className="candidate-loading" role="status">
            جارٍ تحميل حالة المراحل…
          </p>
        ) : (
          <ol className="stage-list">
            <BasicInformationStage stage={overview.basic_information} />
            <li className="stage-card stage-card--upcoming">
              <p className="stage-card__number">المراحل التالية</p>
              <h3 className="stage-card__title">غير متاحة بعد</h3>
              <p className="stage-card__text">ستُتاح المراحل التالية من ملف الترشح لاحقاً، وتظهر هنا عند فتحها.</p>
            </li>
          </ol>
        )}

        <p className="candidate-section__note">
          استكمال البيانات الأساسية خطوة أولى في ملف الترشح، ولا يعني تقديم طلب ترشح للجائزة.
        </p>
      </section>
    </div>
  )
}

function BasicInformationStage({ stage }: { stage: Overview['basic_information'] }) {
  const completed = stage.status === 'completed'

  return (
    <li className={`stage-card${completed ? ' stage-card--completed' : ''}`}>
      <p className="stage-card__number">المرحلة 1</p>
      <h3 className="stage-card__title">استكمال البيانات الأساسية</h3>
      <p className="stage-card__status">
        <StatusIcon tone={completed ? 'success' : 'info'} />
        <span>
          {completed && stage.completed_at
            ? `مكتملة، حُفظت في ${formatDamascusDateTime(stage.completed_at)} بتوقيت دمشق`
            : stage.status === 'unavailable'
              ? 'غير متاحة بعد'
              : 'لم تبدأ بعد'}
        </span>
      </p>
      <p className="stage-card__text">
        {completed
          ? `بيانات ${stage.profile_type ? `المتقدم (${PROFILE_TYPE_LABELS[stage.profile_type]})` : 'المتقدم'} محفوظة ومتاحة للعرض فقط.`
          : stage.status === 'unavailable'
            ? 'يُتاح استكمال البيانات الأساسية بعد نشر سياسة الخصوصية وشروط الاستخدام المعتمدة للجائزة.'
            : 'بيانات المتقدم، فرداً كان أو مؤسسة، ووسائل التواصل معه. تراجعها قبل تأكيد الحفظ.'}
      </p>
      {stage.status !== 'unavailable' && (
        <Link className={`button ${completed ? 'button--secondary' : 'button--primary'}`} href="/dashboard/basic-information">
          {completed ? 'عرض البيانات' : 'ابدأ الاستكمال'}
        </Link>
      )}
    </li>
  )
}
