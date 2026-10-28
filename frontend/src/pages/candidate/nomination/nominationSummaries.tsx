import type { ReactNode } from 'react'
import type { SummaryGroup } from '../basic/ProfileSummary'
import type { DomainOption, NominationDetails } from './nominationApi'
import { DomainArtwork } from './nominationFields'
import type { NominationForm, NominationStepId } from './nominationModel'

/*
 * The groups shown by ProfileSummary for Stage 2: from the form in the
 * review step, and from the confirmed stage in the read-only view (also on
 * the administration's and the judges' pages of a submitted application).
 */

type Group = SummaryGroup<NominationStepId>

type View = {
  domain: { name: string; artwork: string } | null
  subcategory: string | null
  title: string
  purpose: string
  objectives: string
  impact: string
}

const multiline = (text: string): ReactNode => (text.trim() ? <span className="summary-multiline">{text.trim()}</span> : null)

function groups(view: View, editable: boolean): Group[] {
  const step = <S extends NominationStepId>(id: S) => (editable ? id : undefined)

  return [
    {
      title: 'المحور والفئة',
      step: step('domain'),
      rows: [
        {
          label: 'المحور',
          value: view.domain ? (
            <span className="summary-domain">
              <DomainArtwork artwork={view.domain.artwork} domainName={view.domain.name} size="card" />
              <span>{view.domain.name}</span>
            </span>
          ) : null,
        },
        { label: 'الفئة', value: view.subcategory },
      ],
    },
    {
      title: 'تعريف العمل وأثره',
      step: step('work'),
      rows: [
        { label: 'عنوان العمل', value: view.title.trim() || null },
        { label: 'الغاية والرسالة', value: multiline(view.purpose) },
        { label: 'الأهداف', value: multiline(view.objectives) },
        { label: 'الأثر', value: multiline(view.impact) },
      ],
    },
  ]
}

export function nominationSummaryFromForm(form: NominationForm, domains: DomainOption[]): Group[] {
  const domain = domains.find((option) => option.code === form.domain) ?? null

  return groups(
    {
      domain: domain ? { name: domain.name, artwork: domain.artwork } : null,
      subcategory: domain?.subcategories.find((option) => option.code === form.subcategory)?.name ?? null,
      title: form.title,
      purpose: form.purpose_and_mission,
      objectives: form.objectives,
      impact: form.impact,
    },
    true,
  )
}

export function nominationSummaryFromDetails(details: NominationDetails): Group[] {
  return groups(
    {
      domain: { name: details.domain.name, artwork: details.domain.artwork },
      subcategory: details.subcategory.name,
      title: details.title,
      purpose: details.purpose_and_mission,
      objectives: details.objectives,
      impact: details.impact,
    },
    false,
  )
}
