import copy from '../../../../../backend/resources/award/application-progress.json' with { type: 'json' }

export type ProgressStage = 'administrative_review' | 'committee_review' | 'review_completed' | 'tracking_paused'
export type ProgressCode = ProgressStage | 'received'
export type ApplicationProgress = {
  stage: ProgressStage
  reference_number: string | null
  cycle: { code: string; year: number }
  version_number: number | null
  updated_at: string | null
  sequence: number | null
  steps: { code: ProgressCode; state: 'completed' | 'current' | 'waiting' | 'paused'; at: string | null }[]
}

export const progressCopy = copy

export function candidateSignInUrl(location: { pathname: string; search: string; hash: string }): string {
  return `/login?redirect=${encodeURIComponent(location.pathname + location.search + location.hash)}`
}

/** The email route binds the requested journey and version; IDs never select another owner's file. */
export function progressUrl(journeyKey?: string | null, version?: string | null): string {
  const params = new URLSearchParams()
  if (journeyKey) params.set('journey_key', journeyKey)
  if (version) params.set('version', version)
  return `/api/candidate/progress${journeyKey ? '/journey' : ''}${params.size ? `?${params}` : ''}`
}
