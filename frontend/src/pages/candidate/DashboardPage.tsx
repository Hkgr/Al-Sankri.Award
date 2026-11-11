import { useCallback, useEffect, useState } from 'react'
import type { User } from '../../auth/useAuth'
import { FormAlert, StatusIcon } from '../../components/forms/FormControls'
import { useLocale } from '../../i18n/useLocale'
import { api, errorMessage } from '../../lib/api'
import { Link } from '../../lib/router'
import { useRouter } from '../../lib/useRouter'
import { usePageTitle } from '../auth/usePageTitle'
import { formatDamascusDateTime, PROFILE_TYPE_LABELS } from './candidateApi'
import { contextHeader } from './candidateApi'
import type { Overview } from './candidateApi'
import { ApplicationProgressPanel } from './progress/ApplicationProgressPanel'
import { useAccountFailure } from './useCandidateRequests'
import { VerifyEmailNotice } from './VerifyEmailNotice'

/**
 * The candidate's home: a welcome, the three stages of the nomination file
 * and the final review and submission, with their real state from the
 * backend.
 */
export function DashboardPage({ user }: { user: User }) {
  const { t } = useLocale()
  usePageTitle(t('لوحة المترشح'))
  const { location } = useRouter()
  const handleAccountFailure = useAccountFailure()
  const [overview, setOverview] = useState<Overview | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)
  const params = new URLSearchParams(location.search)

  const load = useCallback(async () => {
    setLoadError(null)
    try {
      setOverview((await api.get<{ data: Overview }>('/api/candidate/overview', contextHeader(user))).data)
    } catch (error) {
      if (!handleAccountFailure(error)) setLoadError(errorMessage(error))
    }
  }, [handleAccountFailure, user])

  useEffect(() => {
    // Fetching on mount: the state is set once the request settles.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load()
  }, [load])

  return (
    <div className="candidate-page">
      <header className="candidate-page__header">
        <p className="eyebrow">{t('لوحة المترشح')}</p>
        <h1 className="candidate-page__title">
          {t('مرحباً،')} <bdi>{user.name}</bdi>
        </h1>
        <p className="candidate-page__intro">{t('من هنا تستكمل ملف الترشح لجائزة الدكتور عبد القادر سنكري مرحلةً بعد مرحلة.')}</p>
      </header>

      <ApplicationProgressPanel user={user} journeyKey={params.get('journey_key')} version={params.get('version')} />

      {params.get('welcome') === '1' && (
        <FormAlert tone={params.get('mail') === 'failed' ? 'info' : 'success'}>
          <p>
            {params.get('mail') === 'failed'
              ? t('أُنشئ حسابك، لكن تعذّر إرسال رسالة تأكيد البريد الإلكتروني الآن. اطلب رابطاً جديداً من الأسفل بعد قليل.')
              : t('أُنشئ حسابك، وأرسلنا رابط تأكيد إلى بريدك الإلكتروني.')}
          </p>
        </FormAlert>
      )}
      {params.get('saved') === 'nomination-details' && (
        <FormAlert tone="success">
          <p>{t('حُفظت فئة الترشح وبيانات العمل، وأصبحت متاحة للعرض فقط. هذا ليس تقديماً نهائياً لطلب الترشح؛ تابع إلى ملفات العمل وتوثيقه (المرحلة 3).')}</p>
        </FormAlert>
      )}
      {params.get('saved') === 'basic-information' && (
        <FormAlert tone="success">
          <p>{t('حُفظت بياناتك الأساسية، وأصبحت متاحة للعرض فقط. ستُتاح المراحل التالية من ملف الترشح لاحقاً.')}</p>
        </FormAlert>
      )}

      {!user.email_verified && <VerifyEmailNotice context="dashboard" />}

      <section className="candidate-section" aria-labelledby="stages-title">
        <h2 id="stages-title" className="candidate-section__title">
          {t('مراحل استكمال ملف الترشح')}
        </h2>

        {loadError ? (
          <FormAlert tone="error">
            <p>{loadError}</p>
            <p>
              <button type="button" className="button button--secondary" onClick={() => void load()}>
                {t('إعادة المحاولة')}
              </button>
            </p>
          </FormAlert>
        ) : !overview ? (
          <p className="candidate-loading" role="status">
            {t('جارٍ تحميل حالة المراحل…')}
          </p>
        ) : (
          <ol className="stage-list">
            <BasicInformationStage stage={overview.basic_information} />
            <NominationDetailsStage stage={overview.nomination_details} />
            <DocumentationStage stage={overview.documentation} />
            <SubmissionStage overview={overview} />
          </ol>
        )}

        <p className="candidate-section__note">
          {overview?.submission
            ? t('لا يمكن تعديل المشاركة أو إضافة ملفات إليها بعد الإرسال.')
            : t('استكمال المراحل لا يعني إرسال المشاركة: تُرسل المشاركة من صفحة المراجعة بعد استكمال المراحل الثلاث.')}
        </p>
      </section>
    </div>
  )
}

function BasicInformationStage({ stage }: { stage: Overview['basic_information'] }) {
  const { t, locale } = useLocale()
  const completed = stage.status === 'completed'

  return (
    <li className={`stage-card${completed ? ' stage-card--completed' : ''}`}>
      <p className="stage-card__number">{t('المرحلة 1')}</p>
      <h3 className="stage-card__title">{t('استكمال البيانات الأساسية')}</h3>
      <p className="stage-card__status">
        <StatusIcon tone={completed ? 'success' : 'info'} />
        <span>
          {completed && stage.completed_at
            ? t('مكتملة، حُفظت في {when} بتوقيت دمشق', { when: formatDamascusDateTime(stage.completed_at, locale) })
            : stage.status === 'unavailable'
              ? t('غير متاحة بعد')
              : t('لم تبدأ بعد')}
        </span>
      </p>
      <p className="stage-card__text">
        {completed
          ? stage.profile_type
            ? t('بيانات المتقدم ({type}) محفوظة ومتاحة للعرض فقط.', { type: t(PROFILE_TYPE_LABELS[stage.profile_type]) })
            : t('بيانات المتقدم محفوظة ومتاحة للعرض فقط.')
          : stage.status === 'unavailable'
            ? t('يُتاح استكمال البيانات الأساسية بعد نشر سياسة الخصوصية وشروط الاستخدام المعتمدة للجائزة.')
            : t('بيانات المتقدم، فرداً كان أو مؤسسة، ووسائل التواصل معه. تراجعها قبل تأكيد الحفظ.')}
      </p>
      {stage.status !== 'unavailable' && (
        <Link className={`button ${completed ? 'button--secondary' : 'button--primary'}`} href="/dashboard/basic-information">
          {completed ? t('عرض البيانات') : t('ابدأ الاستكمال')}
        </Link>
      )}
    </li>
  )
}

function DocumentationStage({ stage }: { stage: Overview['documentation'] }) {
  const { t, locale } = useLocale()
  const done = stage.status === 'completed' || stage.status === 'submitted'
  const open = stage.status !== 'locked' && stage.status !== 'unavailable'

  return (
    <li className={`stage-card${done ? ' stage-card--completed' : ''}${open ? '' : ' stage-card--waiting'}`}>
      <p className="stage-card__number">{t('المرحلة 3')}</p>
      <h3 className="stage-card__title">{t('ملفات العمل وتوثيقه')}</h3>
      <p className="stage-card__status">
        <StatusIcon tone={done ? 'success' : 'info'} />
        <span>
          {stage.status === 'submitted'
            ? t('أُرسلت مع المشاركة')
            : stage.status === 'completed' && stage.confirmed_at
              ? t('مكتملة، حُفظت في {when} بتوقيت دمشق', { when: formatDamascusDateTime(stage.confirmed_at, locale) })
              : stage.status === 'locked'
                ? t('تُتاح بعد المرحلة 2')
                : stage.status === 'unavailable'
                  ? t('غير متاحة حالياً')
                  : stage.status === 'in_progress'
                    ? t('قيد الاستكمال')
                    : t('لم تبدأ بعد')}
        </span>
      </p>
      <p className="stage-card__text">
        {stage.status === 'locked'
          ? t('أكّد حفظ فئة الترشح وبيانات العمل أولاً، ثم تُتاح هذه المرحلة.')
          : stage.status === 'unavailable'
            ? t('باب الترشح لهذه الدورة غير مفتوح حالياً.')
            : t('وثيقة شرح العمل (مطلوبة)، ووثائق داعمة وفيديوهات وروابط توثيق اختيارية.')}
      </p>
      {stage.status !== 'locked' && (
        <Link className={`button ${done || stage.status === 'unavailable' ? 'button--secondary' : 'button--primary'}`} href="/dashboard/documentation">
          {done || stage.status === 'unavailable' ? t('عرض الملفات') : stage.status === 'in_progress' ? t('متابعة المرحلة') : t('ابدأ المرحلة')}
        </Link>
      )}
    </li>
  )
}

function SubmissionStage({ overview }: { overview: Overview }) {
  const { t, locale } = useLocale()
  const receipt = overview.submission
  const receiptVerified = !!receipt?.submitted_at && !!receipt.reference_number
  const ready = overview.documentation.status === 'completed'

  return (
    <li className={`stage-card${receiptVerified ? ' stage-card--completed' : ''}${receipt || ready ? '' : ' stage-card--waiting'}`}>
      <p className="stage-card__number">{t('المراجعة والإرسال')}</p>
      <h3 className="stage-card__title">{receipt ? t(receipt.submitted_at ? 'تم إرسال المشاركة' : 'المشاركة متاحة للعرض فقط.') : t('مراجعة المشاركة وإرسالها')}</h3>
      <p className="stage-card__status">
        <StatusIcon tone={receipt?.submitted_at && receipt.reference_number ? 'success' : 'info'} />
        <span>
          {receipt?.submitted_at
            ? t('أُرسلت في {when} بتوقيت دمشق', { when: formatDamascusDateTime(receipt.submitted_at, locale) })
            : receipt ? t('progress.unknownDate') : ready
              ? t('جاهزة للمراجعة')
              : t('تُتاح بعد المرحلة 3')}
        </span>
      </p>
      <p className="stage-card__text">
        {receipt ? (
          <>
            {receipt.reference_number ? <>{t('الرقم المرجعي:')} <bdi dir="ltr">{receipt.reference_number}</bdi>. </> : t('progress.referenceUnavailable')} {t('المشاركة متاحة للعرض فقط.')}
          </>
        ) : (
          t('مراجعة المراحل الثلاث كما حُفظت، ثم إرسال ملف الترشح إلى إدارة الجائزة للمراجعة الأولية.')
        )}
      </p>
      {(receipt || ready) && (
        <Link className={`button ${receipt ? 'button--secondary' : 'button--primary'}`} href="/dashboard/review">
          {receipt ? t('عرض المشاركة') : t('مراجعة المشاركة')}
        </Link>
      )}
    </li>
  )
}

function NominationDetailsStage({ stage }: { stage: Overview['nomination_details'] }) {
  const { t, locale } = useLocale()
  const completed = stage.status === 'completed'
  const open = stage.status === 'not_started' || completed

  return (
    <li className={`stage-card${completed ? ' stage-card--completed' : ''}${open ? '' : ' stage-card--waiting'}`}>
      <p className="stage-card__number">{t('المرحلة 2')}</p>
      <h3 className="stage-card__title">{t('فئة الترشح وبيانات العمل')}</h3>
      <p className="stage-card__status">
        <StatusIcon tone={completed ? 'success' : 'info'} />
        <span>
          {completed && stage.confirmed_at
            ? t('مكتملة، حُفظت في {when} بتوقيت دمشق', { when: formatDamascusDateTime(stage.confirmed_at, locale) })
            : stage.status === 'locked'
              ? t('تُتاح بعد المرحلة 1')
              : stage.status === 'unavailable'
                ? t('غير متاحة حالياً')
                : t('لم تبدأ بعد')}
        </span>
      </p>
      <p className="stage-card__text">
        {completed
          ? t('المحور والفئة وبيانات العمل محفوظة ومتاحة للعرض فقط.')
          : stage.status === 'locked'
            ? t('أكّد حفظ البيانات الأساسية أولاً، ثم تُتاح هذه المرحلة.')
            : stage.status === 'unavailable'
              ? t('لم تفتح إدارة الجائزة هذه المرحلة بعد لدورة الجائزة الحالية.')
              : t('محور الترشح وفئته، وتعريف العمل: عنوانه وغايته ورسالته وأهدافه وأثره.')}
      </p>
      {open && (
        <Link className={`button ${completed ? 'button--secondary' : 'button--primary'}`} href="/dashboard/nomination-details">
          {completed ? t('عرض البيانات') : t('ابدأ المرحلة')}
        </Link>
      )}
    </li>
  )
}
