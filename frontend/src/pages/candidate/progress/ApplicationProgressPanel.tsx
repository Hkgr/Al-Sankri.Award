import { useCallback, useEffect, useRef, useState } from 'react'
import type { User } from '../../../auth/useAuth'
import { FormAlert } from '../../../components/forms/FormControls'
import { useLocale } from '../../../i18n/useLocale'
import { api, errorMessage } from '../../../lib/api'
import { contextHeader, formatDamascusDateTime } from '../candidateApi'
import { useAccountFailure } from '../useCandidateRequests'
import { progressCopy, progressUrl } from './progressApi'
import type { ApplicationProgress } from './progressApi'
import './progress.css'

export function ApplicationProgressPanel({ user, journeyKey, version }: { user: User; journeyKey?: string | null; version?: string | null }) {
  const { t } = useLocale()
  const accountFailure = useAccountFailure()
  const requestKey = `${user.account_context}|${journeyKey ?? ''}|${version ?? ''}`
  const [loaded, setLoaded] = useState<{ key: string; data: ApplicationProgress | null }>()
  const state = loaded?.key === requestKey ? loaded.data : undefined
  const [error, setError] = useState<string | null>(null)
  const [refreshing, setRefreshing] = useState(false)
  const request = useRef<AbortController | null>(null)
  const load = useCallback(async () => {
    request.current?.abort()
    const controller = new AbortController()
    request.current = controller
    setRefreshing(true)
    setError(null)
    try {
      const response = await api.get<{ data: ApplicationProgress | null }>(progressUrl(journeyKey, version), contextHeader(user), controller.signal)
      if (!controller.signal.aborted) setLoaded({ key: requestKey, data: response.data })
    } catch (reason) {
      if (!controller.signal.aborted && !accountFailure(reason)) setError(errorMessage(reason))
    } finally {
      if (!controller.signal.aborted) setRefreshing(false)
    }
  }, [user, journeyKey, version, requestKey, accountFailure])

  useEffect(() => {
    // Initial read and focus refresh; no polling, writes or notification side effects.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load()
    const refresh = () => { if (document.visibilityState === 'visible') void load() }
    window.addEventListener('focus', refresh)
    document.addEventListener('visibilitychange', refresh)
    return () => {
      request.current?.abort()
      window.removeEventListener('focus', refresh)
      document.removeEventListener('visibilitychange', refresh)
    }
  }, [load])

  return <section id="application-progress" className="application-progress" aria-labelledby="progress-title" aria-busy={refreshing}>
    <header className="application-progress__header">
      <div><p className="eyebrow">{t('progress.eyebrow')}</p><h2 id="progress-title">{t('progress.title')}</h2></div>
      <button type="button" className="button button--secondary" onClick={() => void load()} disabled={refreshing}>{t(refreshing ? 'progress.refreshing' : 'progress.refresh')}</button>
    </header>
    {error ? <FormAlert tone="error"><p>{error}</p><button className="button button--secondary" type="button" onClick={() => void load()}>{t('common.retry')}</button></FormAlert>
      : state === undefined ? <p className="application-progress__loading" role="status">{t('progress.loading')}</p>
      : state === null ? <p className="application-progress__empty">{t('progress.empty')}</p>
      : <ProgressTimeline progress={state} />}
  </section>
}

/** The server's allowlisted steps determine the path, including omitted inapplicable stages. */
export function ProgressTimeline({ progress }: { progress: ApplicationProgress }) {
  const { t, locale } = useLocale()
  const current = progressCopy[locale][progress.stage]
  return <>
    <div className="application-progress__summary" aria-live="polite">
      <p className="application-progress__current">{current.label}</p>
      <p>{current.description}</p>
      <div className="application-progress__meta">
        <p>{progress.reference_number ? <>{t('progress.reference')} <bdi dir="ltr">{progress.reference_number}</bdi></> : t('progress.referenceUnavailable')}</p>
        <p>{progress.version_number === null ? t('progress.cycle', { year: progress.cycle.year }) : t('progress.version', { version: progress.version_number, cycle: progress.cycle.year })}</p>
        <p>{progress.updated_at ? t('progress.updated', { when: formatDamascusDateTime(progress.updated_at, locale) }) : t('progress.unknownDate')}</p>
      </div>
    </div>
    <ol className="progress-steps" style={{ gridTemplateColumns: `repeat(${progress.steps.length}, minmax(0, 1fr))` }}>
      {progress.steps.map((step) => <li key={step.code} className={`progress-step progress-step--${step.state}`} aria-current={step.state === 'current' ? 'step' : undefined}>
        <span className="progress-step__marker" aria-hidden="true">{step.state === 'completed' ? <svg viewBox="0 0 24 24" fill="none"><path d="m6 12 4 4 8-8" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" /></svg> : <span className="progress-step__dot" />}</span>
        <div className="progress-step__body"><span className="progress-step__state">{t(`progress.${step.state}`)}</span><h3>{progressCopy[locale][step.code].label}</h3>
          {step.at && step.state !== 'waiting' && <time dateTime={step.at}>{formatDamascusDateTime(step.at, locale)}</time>}
        </div>
      </li>)}
    </ol>
  </>
}
