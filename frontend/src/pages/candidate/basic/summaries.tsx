import type { ReactNode } from 'react'
import { formatCalendarDate, formatDamascusDateTime, GENDER_LABELS, POLICY_NAMES, POLICY_SLUGS, PROFILE_TYPE_LABELS } from '../candidateApi'
import type { BasicProfile, Country } from '../candidateApi'
import { asciiDigits, birthDate, MONTHS } from './formModel'
import type { FormState, PhoneValue, SocialLink } from './formModel'
import { SummaryLinks as LinkList } from './ProfileSummary'
import type { SummaryGroup } from './ProfileSummary'

/*
 * The groups shown by ProfileSummary: from the form in the review step, and
 * from the confirmed profile in the read-only view.
 */

/* ---------- From the form (review step) ---------- */

function countryName(countries: Country[], code: string): string | null {
  return countries.find((country) => country.code === code)?.name ?? null
}

function phoneText(countries: Country[], phone: PhoneValue): string | null {
  const number = asciiDigits(phone.number).trim()
  if (!number) return null
  const code = countries.find((country) => country.code === phone.country)?.calling_code
  return code ? `+${code} ${number}` : number
}

function formLinks(links: SocialLink[]): ReactNode | null {
  return links.length ? <LinkList links={links.map((link) => ({ platform: link.platform.trim(), url: link.url.trim() }))} /> : null
}

function text(value: string): string | null {
  return value.trim() ? value.trim() : null
}

export function summaryFromForm(state: FormState, countries: Country[]): SummaryGroup[] {
  const typeGroup: SummaryGroup = {
    title: 'نوع المتقدم',
    step: 'type',
    rows: [{ label: 'نوع المتقدم', value: state.profileType ? PROFILE_TYPE_LABELS[state.profileType] : null }],
  }

  if (state.profileType === 'individual') {
    const person = state.individual
    const date = birthDate(person)
    return [
      typeGroup,
      {
        title: 'البيانات الشخصية',
        step: 'individual_identity',
        rows: [
          { label: 'الاسم الأول', value: text(person.first_name) },
          { label: 'الاسم الأوسط', value: text(person.middle_name) },
          { label: 'الاسم الأخير', value: text(person.last_name) },
          { label: 'تاريخ الميلاد', value: date ? `${Number(date.slice(8))} ${MONTHS[Number(date.slice(5, 7)) - 1]} ${date.slice(0, 4)}` : null },
          { label: 'الجنسية', value: countryName(countries, person.nationality) },
          { label: 'الجنس', value: person.gender ? GENDER_LABELS[person.gender] : null },
          { label: 'رقم الهوية الشخصية', value: text(asciiDigits(person.national_id_number)), ltr: true },
        ],
      },
      {
        title: 'الإقامة والتواصل',
        step: 'individual_contact',
        rows: [
          { label: 'دولة الإقامة', value: countryName(countries, person.residence_country) },
          { label: 'العنوان', value: text(person.address) },
          { label: 'رقم التواصل الأساسي', value: phoneText(countries, person.primary_phone), ltr: true },
          { label: 'رقم التواصل الاحتياطي', value: phoneText(countries, person.secondary_phone), ltr: true },
          { label: 'البريد الإلكتروني للتواصل', value: text(person.contact_email), ltr: true },
          { label: 'روابط مواقع التواصل الاجتماعي', value: formLinks(person.social_links) },
        ],
      },
    ]
  }

  const institution = state.institution
  return [
    typeGroup,
    {
      title: 'بيانات المؤسسة',
      step: 'institution_details',
      rows: [
        { label: 'اسم المؤسسة', value: text(institution.name) },
        { label: 'الدولة', value: countryName(countries, institution.country) },
        { label: 'نوع النشاط المؤسسي', value: text(institution.activity_type) },
        { label: 'رقم الترخيص', value: text(asciiDigits(institution.license_number)), ltr: true },
      ],
    },
    {
      title: 'الممثل والتواصل',
      step: 'institution_contact',
      rows: [
        { label: 'اسم الشخص المتقدم بالنيابة عن المؤسسة', value: text(institution.representative_name) },
        { label: 'الصفة الوظيفية', value: text(institution.representative_title) },
        { label: 'رقم تواصل المؤسسة', value: phoneText(countries, institution.phone), ltr: true },
        { label: 'البريد الإلكتروني للمؤسسة', value: text(institution.email), ltr: true },
        { label: 'الموقع الإلكتروني', value: text(institution.website), ltr: true },
        { label: 'روابط مواقع التواصل الاجتماعي', value: formLinks(institution.social_links) },
      ],
    },
  ]
}

/* ---------- From the confirmed profile (read-only view) ---------- */

export function summaryFromProfile(profile: BasicProfile): SummaryGroup[] {
  const links = profile.social_links.length ? <LinkList links={profile.social_links} /> : null
  const groups: SummaryGroup[] = [{ title: 'نوع المتقدم', rows: [{ label: 'نوع المتقدم', value: PROFILE_TYPE_LABELS[profile.profile_type] }] }]

  if (profile.individual) {
    const person = profile.individual
    groups.push(
      {
        title: 'البيانات الشخصية',
        rows: [
          { label: 'الاسم الأول', value: person.first_name },
          { label: 'الاسم الأوسط', value: person.middle_name },
          { label: 'الاسم الأخير', value: person.last_name },
          { label: 'تاريخ الميلاد', value: formatCalendarDate(person.birth_date) },
          { label: 'الجنسية', value: person.nationality?.name ?? null },
          { label: 'الجنس', value: GENDER_LABELS[person.gender] },
          { label: 'رقم الهوية الشخصية', value: person.national_id_number, ltr: true },
        ],
      },
      {
        title: 'الإقامة والتواصل',
        rows: [
          { label: 'دولة الإقامة', value: person.residence_country?.name ?? null },
          { label: 'العنوان', value: person.address },
          { label: 'رقم التواصل الأساسي', value: person.primary_phone, ltr: true },
          { label: 'رقم التواصل الاحتياطي', value: person.secondary_phone, ltr: true },
          { label: 'البريد الإلكتروني للتواصل', value: person.contact_email, ltr: true },
          { label: 'روابط مواقع التواصل الاجتماعي', value: links },
        ],
      },
    )
  }

  if (profile.institution) {
    const institution = profile.institution
    groups.push(
      {
        title: 'بيانات المؤسسة',
        rows: [
          { label: 'اسم المؤسسة', value: institution.name },
          { label: 'الدولة', value: institution.country?.name ?? null },
          { label: 'نوع النشاط المؤسسي', value: institution.activity_type },
          { label: 'رقم الترخيص', value: institution.license_number, ltr: true },
        ],
      },
      {
        title: 'الممثل والتواصل',
        rows: [
          { label: 'اسم الشخص المتقدم بالنيابة عن المؤسسة', value: institution.representative_name },
          { label: 'الصفة الوظيفية', value: institution.representative_title },
          { label: 'رقم تواصل المؤسسة', value: institution.phone, ltr: true },
          { label: 'البريد الإلكتروني للمؤسسة', value: institution.email, ltr: true },
          { label: 'الموقع الإلكتروني', value: institution.website, ltr: true },
          { label: 'روابط مواقع التواصل الاجتماعي', value: links },
        ],
      },
    )
  }

  groups.push({
    title: 'الموافقات والإقرارات',
    rows: [
      ...profile.accepted_policies.map((policy) => ({
        label: POLICY_NAMES[policy.code],
        value: (
          <>
            تمت الموافقة على{' '}
            <a href={`/policies/${POLICY_SLUGS[policy.code]}?version=${policy.version_number}`} target="_blank" rel="noopener">
              الإصدار {policy.version_number}
            </a>{' '}
            في {formatDamascusDateTime(policy.accepted_at)}
          </>
        ),
      })),
      { label: 'صحة البيانات المقدمة', value: `تم الإقرار بها في ${formatDamascusDateTime(profile.declarations.data_accuracy_declared_at)}` },
      ...(profile.declarations.representation_declared_at
        ? [{ label: 'تمثيل المؤسسة', value: `تم الإقرار بالتخويل بتمثيلها في ${formatDamascusDateTime(profile.declarations.representation_declared_at)}` }]
        : []),
    ],
  })

  return groups
}
