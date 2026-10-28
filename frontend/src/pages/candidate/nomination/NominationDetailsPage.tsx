import { useCallback, useEffect, useState } from 'react'
import type { User } from '../../../auth/useAuth'
import { FormAlert, StatusIcon } from '../../../components/forms/FormControls'
import { ApiError, api, errorMessage } from '../../../lib/api'
import { Link } from '../../../lib/router'
import { useRouter } from '../../../lib/useRouter'
import { usePageTitle } from '../../auth/usePageTitle'
import { ProfileSummary } from '../basic/ProfileSummary'
import { contextHeader, formatDamascusDateTime } from '../candidateApi'
import { useAccountFailure } from '../useCandidateRequests'
import type { NominationDetails, NominationState } from './nominationApi'
import { DomainArtwork } from './nominationFields'
import { NominationForm } from './NominationForm'
import { nominationSummaryFromDetails } from './nominationSummaries'

type Loaded = { kind: 'state'; state: NominationState } | { kind: 'stage_1_required' }

/**
 * Stage 2: the form while the stage is open and not yet saved, and a
 * permanent read-only summary afterwards (the server refuses any change).
 * Unavailable or not-yet-reachable states say so plainly.
 */
export function NominationDetailsPage({ user }: { user: User }) {
  usePageTitle('فئة الترشح وبيانات العمل')
  const { navigate } = useRouter()
  const handleAccountFailure = useAccountFailure()
  const [loaded, setLoaded] = useState<Loaded | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [storedNotice, setStoredNotice] = useState(false)

  const load = useCallback(async () => {
    setLoadError(null)
    try {
      // Read for the account this page shows; refused (409) if another is signed in now.
      const state = await api.get<NominationState>('/api/candidate/nomination-details', contextHeader(user))
      setLoaded({ kind: 'state', state })
    } catch (error) {
      if (error instanceof ApiError && error.code === 'basic_information_required') {
        setLoaded({ kind: 'stage_1_required' })
        return
      }
      if (!handleAccountFailure(error)) setLoadError(errorMessage(error))
    }
  }, [handleAccountFailure, user])

  useEffect(() => {
    // Fetching on mount: the state is set once the request settles.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load()
  }, [load])

  const onConfirmed = (details: NominationDetails, alreadyStored: boolean) => {
    if (alreadyStored) {
      setLoaded({ kind: 'state', state: { data: details, availability: { status: 'confirmed' } } })
      setStoredNotice(true)
      return
    }
    navigate('/dashboard?saved=nomination-details')
  }

  const state = loaded?.kind === 'state' ? loaded.state : null
  const details = state?.data ?? null

  return (
    <div className="candidate-page">
      <header className="candidate-page__header">
        <p className="eyebrow">المرحلة 2 من ملف الترشح</p>
        <h1 className="candidate-page__title">فئة الترشح وبيانات العمل</h1>
        <p className="candidate-page__intro">
          {details ? 'هذه بيانات المرحلة كما حُفظت. هي متاحة للعرض فقط.' : 'اختر محور الترشح وفئته، وعرّف بالعمل: عنوانه وغايته ورسالته وأهدافه وأثره.'}
        </p>
      </header>

      {loadError ? (
        <FormAlert tone="error">
          <p>{loadError}</p>
          <p>
            <button type="button" className="button button--secondary" onClick={() => void load()}>
              إعادة المحاولة
            </button>
          </p>
        </FormAlert>
      ) : !loaded ? (
        <p className="candidate-loading" role="status">
          جارٍ تحميل البيانات…
        </p>
      ) : loaded.kind === 'stage_1_required' ? (
        <FormAlert tone="info">
          <p>تُتاح هذه المرحلة بعد تأكيد حفظ البيانات الأساسية (المرحلة 1).</p>
          <p>
            <Link href="/dashboard/basic-information">الانتقال إلى البيانات الأساسية</Link>
          </p>
        </FormAlert>
      ) : details ? (
        <ReadOnlyNomination details={details} storedNotice={storedNotice} />
      ) : state && state.availability.status === 'available' && state.options && state.journey_key ? (
        <NominationForm
          accountContext={user.account_context}
          journeyKey={state.journey_key}
          domains={state.options.domains}
          onConfirmed={onConfirmed}
        />
      ) : (
        <FormAlert tone="info">
          <p>
            {state?.availability.status === 'ambiguous'
              ? 'هذه المرحلة غير متاحة حالياً: أكثر من دورة مفتوحة لها في الوقت نفسه، وتحتاج إدارة الجائزة إلى إبقاء دورة واحدة فقط.'
              : 'هذه المرحلة غير متاحة حالياً: لم تفتحها إدارة الجائزة بعد لدورة الجائزة الحالية. لا يُطلب منك إدخال شيء قبل ذلك.'}
          </p>
          <p>
            <Link href="/dashboard">العودة إلى لوحة المترشح</Link>
          </p>
        </FormAlert>
      )}
    </div>
  )
}

function ReadOnlyNomination({ details, storedNotice }: { details: NominationDetails; storedNotice: boolean }) {
  return (
    <>
      {storedNotice && (
        <FormAlert tone="info">
          <p>تأكّدنا من أن بيانات هذه المرحلة محفوظة، وهي متاحة للعرض فقط. لم تُحفظ مرة ثانية ولم يُغيَّر فيها شيء.</p>
        </FormAlert>
      )}
      <div className="locked-banner">
        <StatusIcon tone="success" />
        <div>
          <p className="locked-banner__title">حُفظت فئة الترشح وبيانات العمل</p>
          <p>
            حُفظت في {formatDamascusDateTime(details.confirmed_at)} بتوقيت دمشق، وهي متاحة للعرض فقط. هذا ليس تقديماً نهائياً لطلب الترشح؛ تُرسل المشاركة من صفحة
            المراجعة بعد استكمال ملفات العمل وتوثيقه.
          </p>
        </div>
      </div>
      <div className="domain-preview domain-preview--summary">
        <DomainArtwork artwork={details.domain.artwork} domainName={details.domain.name} size="preview" />
        <div className="domain-preview__text">
          <p className="domain-preview__name">{details.domain.name}</p>
          <p className="domain-preview__sub">{details.subcategory.name}</p>
        </div>
      </div>
      <ProfileSummary groups={nominationSummaryFromDetails(details)} />
      <p className="candidate-page__back">
        <Link href="/dashboard/documentation">المتابعة إلى ملفات العمل وتوثيقه</Link>
      </p>
    </>
  )
}
