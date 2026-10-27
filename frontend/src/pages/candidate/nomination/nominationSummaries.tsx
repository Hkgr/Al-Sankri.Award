import type { ReactNode } from 'react'
import { formatCalendarDate } from '../candidateApi'
import type { SummaryGroup } from '../basic/ProfileSummary'
import { FUNDING_LABELS } from './nominationApi'
import type { DomainOption, FundingType, Governorate, NominationDetails } from './nominationApi'
import { DomainArtwork } from './nominationFields'
import { dateValue, FUNDED_BY_OTHERS, normalizeAmount } from './nominationModel'
import type { NominationForm, NominationStepId } from './nominationModel'

/*
 * The groups shown by ProfileSummary for Stage 2: from the form in the
 * review step, and from the confirmed stage in the read-only view. Only the
 * funding fields that apply to the declaration are listed.
 */

type Group = SummaryGroup<NominationStepId>

type View = {
  domain: { name: string; artwork: string } | null
  subcategory: string | null
  title: string
  purpose: string
  objectives: string
  impact: string
  ongoing: boolean
  startedOn: string
  endedOn: string
  locations: { governorate: string; locality: string; address: string | null }[]
  funding: FundingType | ''
  funder: string
  amount: string
  partnerships: boolean | null
  partners: { name: string; contribution: string | null }[]
}

const multiline = (text: string): ReactNode => (text.trim() ? <span className="summary-multiline">{text.trim()}</span> : null)

const date = (value: string): string | null => (value ? formatCalendarDate(value) : null)

function groups(view: View, editable: boolean): Group[] {
  const step = <S extends NominationStepId>(id: S) => (editable ? id : undefined)
  const amountLabel = view.funding === 'self_funded' ? 'المبلغ المموَّل ذاتياً' : 'مبلغ التمويل من الجهة الممولة'

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
    {
      title: 'التواريخ وأماكن التنفيذ',
      step: step('dates_locations'),
      rows: [
        { label: 'تاريخ بدء العمل', value: date(view.startedOn) },
        { label: 'تاريخ انتهاء العمل', value: view.ongoing ? 'العمل مستمر' : date(view.endedOn) },
        {
          label: 'أماكن التنفيذ',
          value: view.locations.length ? (
            <ol className="summary-list-items">
              {view.locations.map((place, index) => (
                <li key={`${index}-${place.locality}`}>
                  {place.governorate} – {place.locality}
                  {place.address ? `، ${place.address}` : ''}
                </li>
              ))}
            </ol>
          ) : null,
        },
      ],
    },
    {
      title: 'التمويل والشراكات',
      step: step('funding'),
      rows: [
        { label: 'الإقرار المالي', value: view.funding ? FUNDING_LABELS[view.funding] : null },
        ...(view.funding && FUNDED_BY_OTHERS.includes(view.funding) ? [{ label: 'اسم الجهة الممولة', value: view.funder.trim() || null }] : []),
        ...(view.funding && view.funding !== 'not_needed'
          ? [{ label: amountLabel, value: view.amount ? <bdi dir="ltr">{`${view.amount} USD`}</bdi> : null }]
          : []),
        {
          label: 'الشراكات',
          value:
            view.partnerships === null ? null : view.partnerships && view.partners.length ? (
              <ol className="summary-list-items">
                {view.partners.map((partner, index) => (
                  <li key={`${index}-${partner.name}`}>
                    <bdi>{partner.name}</bdi>
                    {partner.contribution ? <span className="summary-multiline">: {partner.contribution}</span> : null}
                  </li>
                ))}
              </ol>
            ) : (
              'لا توجد شراكات'
            ),
        },
      ],
    },
  ]
}

export function nominationSummaryFromForm(form: NominationForm, domains: DomainOption[], governorates: Governorate[]): Group[] {
  const domain = domains.find((option) => option.code === form.domain) ?? null
  const governorateName = (code: string) => governorates.find((option) => option.code === code)?.name ?? code

  return groups(
    {
      domain: domain ? { name: domain.name, artwork: domain.artwork } : null,
      subcategory: domain?.subcategories.find((option) => option.code === form.subcategory)?.name ?? null,
      title: form.title,
      purpose: form.purpose_and_mission,
      objectives: form.objectives,
      impact: form.impact,
      ongoing: form.ongoing,
      startedOn: dateValue(form.start),
      endedOn: dateValue(form.end),
      locations: form.locations.map((row) => ({ governorate: governorateName(row.governorate), locality: row.locality.trim(), address: row.address.trim() || null })),
      funding: form.funding_type,
      funder: form.funder_name,
      amount: normalizeAmount(form.funding_amount),
      partnerships: form.has_partnerships === '' ? null : form.has_partnerships === 'yes',
      partners: form.partners.map((row) => ({ name: row.name.trim(), contribution: row.contribution.trim() || null })),
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
      ongoing: details.implementation.state === 'ongoing',
      startedOn: details.implementation.started_on,
      endedOn: details.implementation.ended_on ?? '',
      locations: details.locations.map((place) => ({ governorate: place.governorate_name, locality: place.locality, address: place.address })),
      funding: details.funding.type,
      funder: details.funding.funder_name ?? '',
      amount: details.funding.amount_usd ?? '',
      partnerships: details.partnerships.has_partnerships,
      partners: details.partnerships.items,
    },
    false,
  )
}
