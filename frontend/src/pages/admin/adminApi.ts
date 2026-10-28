import type { BasicProfile } from '../candidate/candidateApi'
import type { Attachment, DocumentationLink } from '../candidate/documentation/documentationApi'
import type { NominationDetails } from '../candidate/nomination/nominationApi'

/**
 * Types and labels for the administration dashboard (backend: routes/api.php,
 * prefix /api/admin). Every endpoint checks the signed-in account's
 * administration permissions again on the server.
 */

export type AdminSubcategory = { code: string; name: string }
export type AdminDomain = { code: DomainCode; name: string; subcategories: AdminSubcategory[] }
export type AdminCycle = { id: number; code: string; name: string; year: number; status: string; domains: AdminDomain[] }
export type AdminContext = { cycles: AdminCycle[]; default_cycle_id: number | null }

export type DomainCode = 'culture_and_arts' | 'heritage' | 'creativity'

export type ApplicationStatus = 'submitted' | 'completion_requested' | 'eligible' | 'ineligible' | 'in_judging' | 'withdrawn' | 'closed'

export const STATUS_LABELS: Record<ApplicationStatus, string> = {
  submitted: 'مُرسَل',
  completion_requested: 'مطلوب استكماله',
  eligible: 'مستوفٍ للشروط',
  ineligible: 'غير مستوفٍ للشروط',
  in_judging: 'قيد التحكيم',
  withdrawn: 'مسحوب',
  closed: 'مغلق',
}

export const APPLICANT_TYPE_LABELS = { individual: 'فرد', institution: 'مؤسسة' } as const
export type ApplicantType = keyof typeof APPLICANT_TYPE_LABELS

export const CYCLE_STATUS_LABELS: Record<string, string> = { draft: 'مسودة', published: 'منشورة', closed: 'مغلقة', archived: 'مؤرشفة' }

export type ApplicationRow = {
  id: number
  reference_number: string
  title: string
  applicant_name: string
  applicant_type: ApplicantType
  domain: { code: DomainCode; name: string }
  subcategory: { code: string; name: string } | null
  submitted_at: string
  status: ApplicationStatus
  attachment_count: number
}

export type ApplicationsPage = {
  data: ApplicationRow[]
  meta: { page: number; per_page: number; total: number; last_page: number }
}

export type ApplicationDetail = {
  summary: {
    id: number
    reference_number: string
    cycle: { code: string; name: string; year: number }
    domain: { code: DomainCode; name: string; artwork: string } | null
    subcategory: { code: string; name: string } | null
    status: ApplicationStatus
    submitted_at: string
    version_number: number
    content_hash: string
  }
  basic_information: BasicProfile | null
  nomination_details: NominationDetails | null
  documentation: { confirmed_at: string | null; attachments: Attachment[]; links: DocumentationLink[] }
  judging: {
    ready: boolean
    expected: number
    received: number
    decision: string | null
    scores: { name: string; score: string | null; notes: string | null; sent_at: string | null }[]
  }
  declarations: {
    data_accuracy_declared_at: string | null
    accepted_policies: { code: string; title: string | null; version_number: number; accepted_at: string | null; text_sha256: string }[]
  }
}

export { TABLE_PARAMS } from './applications/tableState'

export const SORT_LABELS = {
  submitted_at: 'تاريخ الإرسال',
  reference: 'الرقم المرجعي',
  title: 'عنوان العمل',
  applicant: 'اسم المترشح',
  status: 'الحالة',
} as const
export type SortKey = keyof typeof SORT_LABELS

export function adminFileUrl(applicationId: number, attachment: Pick<Attachment, 'id'>): string {
  return `/api/admin/applications/${applicationId}/files/${attachment.id}`
}

export type MemberAccount = { active: boolean; email_verified: boolean; setup_pending: boolean }

export type CommitteeMember = {
  id: number
  name: string
  email: string
  phone: string | null
  specialty: string | null
  domain: string
  joined_at: string
  left_at: string | null
  account: MemberAccount
}

export type Committee = {
  id: number
  name: string
  status: 'draft' | 'approved'
  approved_at: string | null
  current_count: number
  allowed_sizes: number[]
  has_judging_history: boolean
  members: CommitteeMember[]
  former_members: CommitteeMember[]
}

export type DomainCommittee = { domain: { code: DomainCode; name: string }; committee: Committee | null }

export type AccountSummary = {
  /** Binds a confirmation to this exact account and email (checked again by the server). */
  account_ref: string
  name: string
  email: string
  phone: string | null
  active: boolean
  email_verified: boolean
  setup_pending: boolean
  is_admin: boolean
  judging_domain: string | null
  submitted_in_cycle: boolean
}

export type CommitteesResponse = { data: DomainCommittee[]; message?: string }

export type AppointmentResult = { member_id: number; account_created: boolean; setup_link: 'sent' | 'throttled' | 'failed' | null }

export function cycleLabel(cycle: Pick<AdminCycle, 'name' | 'year'>): string {
  // A dash, not parentheses: a parenthesised Latin year after Arabic text reorders oddly in RTL.
  return `${cycle.name} — ${cycle.year}`
}

export type UserProgressState =
  | 'no_basic_information'
  | 'awaiting_nomination'
  | 'awaiting_documentation'
  | 'documentation_incomplete'
  | 'ready'
  | 'submitted'
  | 'not_required'
  | 'basic_information_only'

/** The overall candidate state in the selected cycle (RegisteredUsers::PROGRESS). */
export const PROGRESS_LABELS: Record<UserProgressState, string> = {
  no_basic_information: 'لم يستكمل البيانات الأساسية',
  awaiting_nomination: 'بانتظار فئة الترشح وبيانات العمل',
  awaiting_documentation: 'بانتظار ملفات العمل',
  documentation_incomplete: 'توثيق العمل غير مكتمل',
  ready: 'جاهز للإرسال',
  submitted: 'تم إرسال ملف الترشح',
  not_required: 'غير مطلوب لهذا الدور',
  basic_information_only: 'البيانات الأساسية مكتملة (لا دورة)',
}

export type StageState = 'complete' | 'incomplete' | 'none'

export type UserRow = {
  id: number
  name: string
  email: string
  email_verified: boolean
  active: boolean
  registered_at: string | null
  last_login_at: string | null
  roles: {
    platform: { code: string; name: string }[]
    cycle: { code: string; name: string; cycle: { id: number; name: string; year: number }; domain: string | null }[]
  }
  progress: { state: UserProgressState; stage1: StageState; stage2: StageState; stage3: StageState }
  submission: { application_id: number | null; reference_number: string; submitted_at: string | null; status: ApplicationStatus } | null
}

export type UsersPage = {
  data: UserRow[]
  meta: {
    page: number
    per_page: number
    total: number
    last_page: number
    cycle: { id: number; code: string; name: string; year: number; stage_open: boolean } | null
  }
}

export const USER_ROLE_FILTERS = { candidate: 'مترشح', award_admin: 'مدير الجائزة', judge: 'حكَم في الدورة المختارة' } as const

export const USER_SORT_LABELS = { name: 'الاسم', registered_at: 'تاريخ التسجيل', last_login_at: 'آخر تسجيل دخول' } as const
export type UserSortKey = keyof typeof USER_SORT_LABELS
