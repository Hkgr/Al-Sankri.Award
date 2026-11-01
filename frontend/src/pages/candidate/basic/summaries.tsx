import type { ReactNode } from 'react'
import { formatCalendarDate, formatDamascusDateTime, GENDER_LABELS, POLICY_NAMES, POLICY_SLUGS, PROFILE_TYPE_LABELS } from '../candidateApi'
import type { BasicProfile, Country } from '../candidateApi'
import { asciiDigits, birthDate, MONTHS } from './formModel'
import type { FormState, PhoneValue, SocialLink } from './formModel'
import { SummaryLinks as LinkList } from './ProfileSummary'
import type { SummaryGroup } from './ProfileSummary'
import { getLocale, tr } from '../../../i18n/messages'

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
    title: tr('نوع المتقدم'),
    step: 'type',
    rows: [{ label: tr('نوع المتقدم'), value: state.profileType ? tr(PROFILE_TYPE_LABELS[state.profileType]) : null }],
  }

  if (state.profileType === 'individual') {
    const person = state.individual
    const date = birthDate(person)
    return [
      typeGroup,
      {
        title: tr('البيانات الشخصية'),
        step: 'individual_identity',
        rows: [
          { label: tr('الاسم الأول'), value: text(person.first_name) },
          { label: tr('الاسم الأوسط'), value: text(person.middle_name) },
          { label: tr('الاسم الأخير'), value: text(person.last_name) },
          { label: tr('تاريخ الميلاد'), value: date ? `${Number(date.slice(8))} ${tr(MONTHS[Number(date.slice(5, 7)) - 1])} ${date.slice(0, 4)}` : null },
          { label: tr('الجنسية'), value: countryName(countries, person.nationality) },
          { label: tr('الجنس'), value: person.gender ? tr(GENDER_LABELS[person.gender]) : null },
          { label: tr('رقم الهوية الشخصية'), value: text(asciiDigits(person.national_id_number)), ltr: true },
        ],
      },
      {
        title: tr('الإقامة والتواصل'),
        step: 'individual_contact',
        rows: [
          { label: tr('دولة الإقامة'), value: countryName(countries, person.residence_country) },
          { label: tr('العنوان'), value: text(person.address) },
          { label: tr('رقم التواصل الأساسي'), value: phoneText(countries, person.primary_phone), ltr: true },
          { label: tr('رقم التواصل الاحتياطي'), value: phoneText(countries, person.secondary_phone), ltr: true },
          { label: tr('البريد الإلكتروني للتواصل'), value: text(person.contact_email), ltr: true },
          { label: tr('روابط مواقع التواصل الاجتماعي'), value: formLinks(person.social_links) },
        ],
      },
    ]
  }

  const institution = state.institution
  return [
    typeGroup,
    {
      title: tr('بيانات المؤسسة'),
      step: 'institution_details',
      rows: [
        { label: tr('اسم المؤسسة'), value: text(institution.name) },
        { label: tr('الدولة'), value: countryName(countries, institution.country) },
        { label: tr('نوع النشاط المؤسسي'), value: text(institution.activity_type) },
        { label: tr('رقم الترخيص'), value: text(asciiDigits(institution.license_number)), ltr: true },
      ],
    },
    {
      title: tr('الممثل والتواصل'),
      step: 'institution_contact',
      rows: [
        { label: tr('اسم الشخص المتقدم بالنيابة عن المؤسسة'), value: text(institution.representative_name) },
        { label: tr('الصفة الوظيفية'), value: text(institution.representative_title) },
        { label: tr('رقم تواصل المؤسسة'), value: phoneText(countries, institution.phone), ltr: true },
        { label: tr('البريد الإلكتروني للمؤسسة'), value: text(institution.email), ltr: true },
        { label: tr('الموقع الإلكتروني'), value: text(institution.website), ltr: true },
        { label: tr('روابط مواقع التواصل الاجتماعي'), value: formLinks(institution.social_links) },
      ],
    },
  ]
}

/* ---------- From the confirmed profile (read-only view) ---------- */

export function summaryFromProfile(profile: BasicProfile): SummaryGroup[] {
  const links = profile.social_links.length ? <LinkList links={profile.social_links} /> : null
  const groups: SummaryGroup[] = [{ title: tr('نوع المتقدم'), rows: [{ label: tr('نوع المتقدم'), value: tr(PROFILE_TYPE_LABELS[profile.profile_type]) }] }]

  if (profile.individual) {
    const person = profile.individual
    groups.push(
      {
        title: tr('البيانات الشخصية'),
        rows: [
          { label: tr('الاسم الأول'), value: person.first_name },
          { label: tr('الاسم الأوسط'), value: person.middle_name },
          { label: tr('الاسم الأخير'), value: person.last_name },
          { label: tr('تاريخ الميلاد'), value: formatCalendarDate(person.birth_date) },
          { label: tr('الجنسية'), value: person.nationality?.name ?? null },
          { label: tr('الجنس'), value: tr(GENDER_LABELS[person.gender]) },
          { label: tr('رقم الهوية الشخصية'), value: person.national_id_number, ltr: true },
        ],
      },
      {
        title: tr('الإقامة والتواصل'),
        rows: [
          { label: tr('دولة الإقامة'), value: person.residence_country?.name ?? null },
          { label: tr('العنوان'), value: person.address },
          { label: tr('رقم التواصل الأساسي'), value: person.primary_phone, ltr: true },
          { label: tr('رقم التواصل الاحتياطي'), value: person.secondary_phone, ltr: true },
          { label: tr('البريد الإلكتروني للتواصل'), value: person.contact_email, ltr: true },
          { label: tr('روابط مواقع التواصل الاجتماعي'), value: links },
        ],
      },
    )
  }

  if (profile.institution) {
    const institution = profile.institution
    groups.push(
      {
        title: tr('بيانات المؤسسة'),
        rows: [
          { label: tr('اسم المؤسسة'), value: institution.name },
          { label: tr('الدولة'), value: institution.country?.name ?? null },
          { label: tr('نوع النشاط المؤسسي'), value: institution.activity_type },
          { label: tr('رقم الترخيص'), value: institution.license_number, ltr: true },
        ],
      },
      {
        title: tr('الممثل والتواصل'),
        rows: [
          { label: tr('اسم الشخص المتقدم بالنيابة عن المؤسسة'), value: institution.representative_name },
          { label: tr('الصفة الوظيفية'), value: institution.representative_title },
          { label: tr('رقم تواصل المؤسسة'), value: institution.phone, ltr: true },
          { label: tr('البريد الإلكتروني للمؤسسة'), value: institution.email, ltr: true },
          { label: tr('الموقع الإلكتروني'), value: institution.website, ltr: true },
          { label: tr('روابط مواقع التواصل الاجتماعي'), value: links },
        ],
      },
    )
  }

  groups.push({
    title: tr('الموافقات والإقرارات'),
    rows: [
      ...profile.accepted_policies.map((policy) => ({
        label: tr(POLICY_NAMES[policy.code]),
        value: (
          <>
            {tr('تمت الموافقة على')}{' '}
            <a href={`/policies/${POLICY_SLUGS[policy.code]}?version=${policy.version_number}`} target="_blank" rel="noopener">
              {tr('الإصدار {n}', { n: policy.version_number })}
            </a>{' '}
            {tr('في {when}', { when: formatDamascusDateTime(policy.accepted_at, getLocale()) })}
          </>
        ),
      })),
      { label: tr('صحة البيانات المقدمة'), value: tr('تم الإقرار بها في {data_accuracy_declared_at}', { data_accuracy_declared_at: formatDamascusDateTime(profile.declarations.data_accuracy_declared_at) }) },
      ...(profile.declarations.representation_declared_at
        ? [{ label: tr('تمثيل المؤسسة'), value: tr('تم الإقرار بالتخويل بتمثيلها في {representation_declared_at}', { representation_declared_at: formatDamascusDateTime(profile.declarations.representation_declared_at) }) }]
        : []),
    ],
  })

  return groups
}
