import { useCallback, useEffect, useState } from 'react'
import type { User } from '../../../auth/useAuth'
import { FormAlert, StatusIcon } from '../../../components/forms/FormControls'
import { api, errorMessage } from '../../../lib/api'
import { Link } from '../../../lib/router'
import { useRouter } from '../../../lib/useRouter'
import { usePageTitle } from '../../auth/usePageTitle'
import { contextHeader, formatDamascusDateTime } from '../candidateApi'
import type { BasicProfile, Country, Requirements } from '../candidateApi'
import { useAccountFailure } from '../useCandidateRequests'
import { BasicInformationForm } from './BasicInformationForm'
import { ProfileSummary } from './ProfileSummary'
import { summaryFromProfile } from './summaries'

type Loaded = { profile: BasicProfile | null; requirements: Requirements; countries: Country[] }

/**
 * Stage 1: the form until the basic information is confirmed, and a
 * permanent read-only summary afterwards (the server refuses any change).
 */
export function BasicInformationPage({ user }: { user: User }) {
  usePageTitle('البيانات الأساسية')
  const { navigate } = useRouter()
  const handleAccountFailure = useAccountFailure()
  const [loaded, setLoaded] = useState<Loaded | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [storedNotice, setStoredNotice] = useState(false)

  const load = useCallback(async () => {
    setLoadError(null)
    try {
      const [state, reference] = await Promise.all([
        // Read for the account this page shows; refused (409) if another is signed in now.
        api.get<{ data: BasicProfile | null; requirements: Requirements }>('/api/candidate/basic-profile', contextHeader(user)),
        api.get<{ countries: Country[] }>('/api/candidate/reference-data'),
      ])
      setLoaded({ profile: state.data, requirements: state.requirements, countries: reference.countries })
    } catch (error) {
      if (!handleAccountFailure(error)) setLoadError(errorMessage(error))
    }
  }, [handleAccountFailure, user])

  useEffect(() => {
    // Fetching on mount: the state is set once the requests settle.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load()
  }, [load])

  const onConfirmed = (profile: BasicProfile, alreadyStored: boolean) => {
    if (alreadyStored) {
      // This account's information was already saved (an earlier attempt, or
      // a save whose answer was lost): show what is stored, unchanged.
      setLoaded((current) => (current ? { ...current, profile } : current))
      setStoredNotice(true)
      return
    }
    navigate('/dashboard?saved=basic-information')
  }

  const profile = loaded?.profile ?? null

  return (
    <div className="candidate-page">
      <header className="candidate-page__header">
        <p className="eyebrow">المرحلة 1 من ملف الترشح</p>
        <h1 className="candidate-page__title">{profile ? 'البيانات الأساسية' : 'استكمال البيانات الأساسية'}</h1>
        <p className="candidate-page__intro">
          {profile
            ? 'هذه بياناتك الأساسية كما حُفظت. هي متاحة للعرض فقط.'
            : 'أدخل بيانات المتقدم ووسائل التواصل معه، ثم راجعها قبل تأكيد الحفظ.'}
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
      ) : profile ? (
        <ReadOnlyProfile profile={profile} storedNotice={storedNotice} />
      ) : !loaded.requirements.finalization_available ? (
        <FormAlert tone="info">
          <p>
            استكمال البيانات الأساسية غير متاح بعد: لم تُنشر سياسة الخصوصية وشروط الاستخدام المعتمدة للجائزة. ستتمكن من البدء حين نشرها، ولن
            يُطلب منك إدخال شيء قبل ذلك.
          </p>
          <p>
            <Link href="/dashboard">العودة إلى لوحة المترشح</Link>
          </p>
        </FormAlert>
      ) : (
        <BasicInformationForm
          user={user}
          countries={loaded.countries}
          requirements={loaded.requirements}
          accountContext={user.account_context}
          onRequirementsChange={(requirements) => setLoaded((current) => (current ? { ...current, requirements } : current))}
          onConfirmed={onConfirmed}
        />
      )}
    </div>
  )
}

function ReadOnlyProfile({ profile, storedNotice }: { profile: BasicProfile; storedNotice: boolean }) {
  return (
    <>
      {storedNotice && (
        <FormAlert tone="info">
          <p>تأكّدنا من أن بياناتك الأساسية محفوظة، وهي متاحة للعرض فقط. لم تُحفظ مرة ثانية ولم يُغيَّر فيها شيء.</p>
        </FormAlert>
      )}
      <div className="locked-banner">
        <StatusIcon tone="success" />
        <div>
          <p className="locked-banner__title">حُفظت البيانات الأساسية</p>
          <p>
            حُفظت في {formatDamascusDateTime(profile.completed_at)} بتوقيت دمشق، وهي متاحة للعرض فقط ولا يمكن تعديلها من حسابك. ستُتاح
            المراحل التالية من ملف الترشح لاحقاً.
          </p>
        </div>
      </div>
      <ProfileSummary groups={summaryFromProfile(profile)} />
      <p className="candidate-page__back">
        <Link href="/dashboard">العودة إلى لوحة المترشح</Link>
      </p>
    </>
  )
}
