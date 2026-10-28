import { useCallback, useEffect, useState } from 'react'
import type { User } from '../../auth/useAuth'
import { FormAlert, StatusIcon } from '../../components/forms/FormControls'
import { api, errorMessage } from '../../lib/api'
import { Link } from '../../lib/router'
import { useRouter } from '../../lib/useRouter'
import { usePageTitle } from '../auth/usePageTitle'
import { formatDamascusDateTime, PROFILE_TYPE_LABELS } from './candidateApi'
import { contextHeader } from './candidateApi'
import type { Overview } from './candidateApi'
import { PROCESSING_TEXT } from './documentation/documentationApi'
import { useAccountFailure } from './useCandidateRequests'
import { VerifyEmailNotice } from './VerifyEmailNotice'

/**
 * The candidate's home: a welcome, the three stages of the nomination file
 * and the final review and submission, with their real state from the
 * backend.
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
      {params.get('saved') === 'nomination-details' && (
        <FormAlert tone="success">
          <p>حُفظت فئة الترشح وبيانات العمل، وأصبحت متاحة للعرض فقط. هذا ليس تقديماً نهائياً لطلب الترشح؛ تابع إلى ملفات العمل وتوثيقه (المرحلة 3).</p>
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
            <NominationDetailsStage stage={overview.nomination_details} />
            <DocumentationStage stage={overview.documentation} />
            <SubmissionStage overview={overview} />
          </ol>
        )}

        <p className="candidate-section__note">
          {overview?.submission
            ? `أُرسل ملف الترشح، وأصبح كله متاحاً للعرض فقط. ${PROCESSING_TEXT[overview.submission.processing] ?? PROCESSING_TEXT.pending}`
            : 'استكمال المراحل لا يعني إرسال المشاركة: تُرسل المشاركة من صفحة المراجعة بعد استكمال المراحل الثلاث.'}
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

function DocumentationStage({ stage }: { stage: Overview['documentation'] }) {
  const done = stage.status === 'completed' || stage.status === 'submitted'
  const open = stage.status !== 'locked' && stage.status !== 'unavailable'

  return (
    <li className={`stage-card${done ? ' stage-card--completed' : ''}${open ? '' : ' stage-card--waiting'}`}>
      <p className="stage-card__number">المرحلة 3</p>
      <h3 className="stage-card__title">ملفات العمل وتوثيقه</h3>
      <p className="stage-card__status">
        <StatusIcon tone={done ? 'success' : 'info'} />
        <span>
          {stage.status === 'submitted'
            ? 'أُرسلت مع المشاركة'
            : stage.status === 'completed' && stage.confirmed_at
              ? `مكتملة، حُفظت في ${formatDamascusDateTime(stage.confirmed_at)} بتوقيت دمشق`
              : stage.status === 'locked'
                ? 'تُتاح بعد المرحلة 2'
                : stage.status === 'unavailable'
                  ? 'غير متاحة حالياً'
                  : stage.status === 'in_progress'
                    ? 'قيد الاستكمال'
                    : 'لم تبدأ بعد'}
        </span>
      </p>
      <p className="stage-card__text">
        {stage.status === 'locked'
          ? 'أكّد حفظ فئة الترشح وبيانات العمل أولاً، ثم تُتاح هذه المرحلة.'
          : stage.status === 'unavailable'
            ? 'باب الترشح لهذه الدورة غير مفتوح حالياً.'
            : 'وثيقة شرح العمل (مطلوبة)، ووثائق داعمة وفيديوهات وروابط توثيق اختيارية.'}
      </p>
      {stage.status !== 'locked' && (
        <Link className={`button ${done || stage.status === 'unavailable' ? 'button--secondary' : 'button--primary'}`} href="/dashboard/documentation">
          {done || stage.status === 'unavailable' ? 'عرض الملفات' : stage.status === 'in_progress' ? 'متابعة المرحلة' : 'ابدأ المرحلة'}
        </Link>
      )}
    </li>
  )
}

function SubmissionStage({ overview }: { overview: Overview }) {
  const receipt = overview.submission
  const ready = overview.documentation.status === 'completed'

  return (
    <li className={`stage-card${receipt ? ' stage-card--completed' : ''}${receipt || ready ? '' : ' stage-card--waiting'}`}>
      <p className="stage-card__number">المراجعة والإرسال</p>
      <h3 className="stage-card__title">{receipt ? 'تم إرسال المشاركة' : 'مراجعة المشاركة وإرسالها'}</h3>
      <p className="stage-card__status">
        <StatusIcon tone={receipt ? 'success' : 'info'} />
        <span>
          {receipt
            ? `أُرسلت في ${formatDamascusDateTime(receipt.submitted_at)} بتوقيت دمشق`
            : ready
              ? 'جاهزة للمراجعة'
              : 'تُتاح بعد المرحلة 3'}
        </span>
      </p>
      <p className="stage-card__text">
        {receipt ? (
          <>
            الرقم المرجعي: <bdi dir="ltr">{receipt.reference_number}</bdi>. المشاركة متاحة للعرض فقط.
          </>
        ) : (
          'مراجعة المراحل الثلاث كما حُفظت، ثم إرسال ملف الترشح إلى إدارة الجائزة للمراجعة الأولية.'
        )}
      </p>
      {(receipt || ready) && (
        <Link className={`button ${receipt ? 'button--secondary' : 'button--primary'}`} href="/dashboard/review">
          {receipt ? 'عرض المشاركة' : 'مراجعة المشاركة'}
        </Link>
      )}
    </li>
  )
}

function NominationDetailsStage({ stage }: { stage: Overview['nomination_details'] }) {
  const completed = stage.status === 'completed'
  const open = stage.status === 'not_started' || completed

  return (
    <li className={`stage-card${completed ? ' stage-card--completed' : ''}${open ? '' : ' stage-card--waiting'}`}>
      <p className="stage-card__number">المرحلة 2</p>
      <h3 className="stage-card__title">فئة الترشح وبيانات العمل</h3>
      <p className="stage-card__status">
        <StatusIcon tone={completed ? 'success' : 'info'} />
        <span>
          {completed && stage.confirmed_at
            ? `مكتملة، حُفظت في ${formatDamascusDateTime(stage.confirmed_at)} بتوقيت دمشق`
            : stage.status === 'locked'
              ? 'تُتاح بعد المرحلة 1'
              : stage.status === 'unavailable'
                ? 'غير متاحة حالياً'
                : 'لم تبدأ بعد'}
        </span>
      </p>
      <p className="stage-card__text">
        {completed
          ? 'المحور والفئة وبيانات العمل محفوظة ومتاحة للعرض فقط.'
          : stage.status === 'locked'
            ? 'أكّد حفظ البيانات الأساسية أولاً، ثم تُتاح هذه المرحلة.'
            : stage.status === 'unavailable'
              ? 'لم تفتح إدارة الجائزة هذه المرحلة بعد لدورة الجائزة الحالية.'
              : 'محور الترشح وفئته، وتعريف العمل: عنوانه وغايته ورسالته وأهدافه وأثره.'}
      </p>
      {open && (
        <Link className={`button ${completed ? 'button--secondary' : 'button--primary'}`} href="/dashboard/nomination-details">
          {completed ? 'عرض البيانات' : 'ابدأ المرحلة'}
        </Link>
      )}
    </li>
  )
}
