import { useEffect, useState } from 'react'
import { FormAlert } from '../../components/forms/FormControls'
import { ApiError, api, errorMessage } from '../../lib/api'
import { useSearchParam } from '../../lib/useRouter'
import { AuthLayout } from '../auth/AuthLayout'
import { formatDamascusDateTime, POLICY_NAMES } from '../candidate/candidateApi'
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
            ? { tone: 'info', text: `لم تُنشر ${POLICY_NAMES[code]} المعتمدة بعد${query ? ' بهذا الإصدار' : ''}.` }
            : { tone: 'error', text: errorMessage(error) },
        )
      })
    return () => {
      active = false
    }
  }, [code, slug, version])

  return (
    <AuthLayout title={policy?.title ?? POLICY_NAMES[code]} wide>
      {problem && (
        <FormAlert tone={problem.tone}>
          <p>{problem.text}</p>
        </FormAlert>
      )}
      {!policy && !problem && (
        <p className="auth-loading" role="status">
          جارٍ التحميل…
        </p>
      )}
      {policy && (
        <>
          <p className="auth-panel__text">
            الإصدار {policy.version_number}
            {policy.published_at && ` · نُشر في ${formatDamascusDateTime(policy.published_at)} بتوقيت دمشق`}
          </p>
          {policy.status === 'retired' && (
            <FormAlert tone="info">
              <p>هذا إصدار سابق، وقد حلّ محله إصدار أحدث.</p>
            </FormAlert>
          )}
          <div className="policy-text">{policy.body}</div>
        </>
      )}
    </AuthLayout>
  )
}
