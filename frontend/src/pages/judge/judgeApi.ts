import type { User } from '../../auth/useAuth'
import type { Attachment, DocumentationLink } from '../candidate/documentation/documentationApi'
import type { NominationDetails } from '../candidate/nomination/nominationApi'
import type { DomainCode } from '../admin/adminApi'

/** The signed-in judge's projects (backend prefix /api/judge). */

export type JudgeEvaluation = {
  status: 'pending' | 'sent'
  score: string | null
  notes?: string | null
  recommendations?: string | null
  excluded?: boolean
  sent_at?: string | null
}

export type JudgeProjectRow = {
  id: number
  reference_number: string
  title: string
  domain: { code: DomainCode; name: string }
  subcategory: { code: string; name: string } | null
  judging_open: boolean
  evaluation: Pick<JudgeEvaluation, 'status' | 'score' | 'excluded'>
}

export type JudgeProject = {
  summary: {
    id: number
    reference_number: string
    cycle: { code: string; name: string; year: number }
    domain: { code: DomainCode; name: string } | null
    subcategory: { code: string; name: string } | null
    status: string
    submitted_at: string
    version_number: number
    content_hash: string
  }
  nomination_details: NominationDetails | null
  documentation: { confirmed_at: string | null; attachments: Attachment[]; links: DocumentationLink[] }
  judging_open: boolean
  evaluation: JudgeEvaluation
}

export function judgeFileUrl(user: Pick<User, 'account_context'>, applicationId: number, attachment: Pick<Attachment, 'id'>): string {
  const query = new URLSearchParams({ account_context: user.account_context })
  return `/api/judge/projects/${applicationId}/files/${attachment.id}?${query}`
}
