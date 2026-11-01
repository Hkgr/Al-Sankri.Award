import { useEffect, useState } from 'react'
import { FormAlert } from '../../components/forms/FormControls'
import { useLocale } from '../../i18n/useLocale'
import { ApiError, api, errorMessage } from '../../lib/api'
import { useSearchParam } from '../../lib/useRouter'
import { AuthLayout } from '../auth/AuthLayout'
import { formatDamascusDateTime, POLICY_NAME_KEYS } from '../candidate/candidateApi'
import type { PolicyCode } from '../candidate/candidateApi'

type Policy = {
  code: PolicyCode
  title: string
  body: string
  version_number: number
  status: 'published' | 'retired'
  published_at: string | null
}

/**
 * A published policy (or an earlier published version with ?version=), as
 * stored: the exact text an account accepts. Without a published version it
 * says so plainly; no placeholder text is ever shown as the policy.
 */
export function PolicyPage({ code, slug }: { code: PolicyCode; slug: string }) {
  const { t, locale } = useLocale()
  const version = useSearchParam('version')
  const [policy, setPolicy] = useState<Policy | null>(null)
  const [problem, setProblem] = useState<{ tone: 'info' | 'error'; text: string } | null>(null)

  useEffect(() => {
    let active = true
    const query = version && /^\d+$/.test(version) ? `?version=${version}` : ''
    api
      .get<{ data: Policy }>(`/api/policies/${slug}${query}`)
      .then((response) => {
        if (!active) return
        setPolicy(response.data)
        setProblem(null)
      })
      .catch((error: unknown) => {
        if (!active) return
        setPolicy(null)
        setProblem(
          error instanceof ApiError && error.code === 'policy_not_configured'
            ? { tone: 'info', text: t(query ? 'policy.notPublishedVersion' : 'policy.notPublished', { name: t(POLICY_NAME_KEYS[code]) }) }
            : { tone: 'error', text: errorMessage(error) },
        )
      })
    return () => {
      active = false
    }
  }, [code, slug, version, t])

  return (
    <AuthLayout title={policy?.title ?? t(POLICY_NAME_KEYS[code])} wide>
      {problem && (
        <FormAlert tone={problem.tone}>
          <p>{problem.text}</p>
        </FormAlert>
      )}
      {!policy && !problem && (
        <p className="auth-loading" role="status">
          {t('policy.loading')}
        </p>
      )}
      {policy && (
        <>
          <p className="auth-panel__text">
            {t('policy.version', { n: policy.version_number })}
            {policy.published_at && ` · ${t('policy.publishedAt', { when: formatDamascusDateTime(policy.published_at, locale) })}`}
          </p>
          {policy.status === 'retired' && (
            <FormAlert tone="info">
              <p>{t('policy.retired')}</p>
            </FormAlert>
          )}
          <div className="policy-text">{policy.body}</div>
        </>
      )}
    </AuthLayout>
  )
}
