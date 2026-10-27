<?php

namespace App\Http\Requests\Candidate;

use App\Models\AccountPolicyVersion;
use App\Models\ApplicantIndividualDetail;
use App\Models\ApplicantSocialLink;
use App\Models\CandidateProfile;
use App\Support\Countries;
use App\Support\PhoneNumbers;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Stage 1 basic information: an individual or an institution, never both.
 *
 * Only the fields listed here are read (validated()), the inactive branch is
 * rejected, and nothing about roles, status, verification or completion is
 * accepted from the request. Arabic-Indic and Persian digits are converted
 * to ASCII digits in numeric fields; names and identifiers keep every other
 * character as entered.
 *
 * Length limits: names 80 characters each, a representative's name and
 * titles 150, an institution's name 255, activity 150, address 500,
 * identity and licence numbers 50, emails 255, links 500 (at most five).
 */
class BasicProfileRequest extends FormRequest
{
    /**
     * The fields of each internal section of the form, so that a section can
     * be checked on its own before the final review (BasicProfileSectionRequest).
     *
     * @var array<string, list<string>>
     */
    public const SECTIONS = [
        'individual_identity' => [
            'individual.first_name', 'individual.middle_name', 'individual.last_name', 'individual.birth_date',
            'individual.nationality', 'individual.gender', 'individual.national_id_number',
        ],
        'individual_contact' => [
            'individual.residence_country', 'individual.address',
            'individual.primary_phone', 'individual.primary_phone.country', 'individual.primary_phone.number',
            'individual.secondary_phone', 'individual.secondary_phone.country', 'individual.secondary_phone.number',
            'individual.contact_email', 'social_links', 'social_links.*', 'social_links.*.platform', 'social_links.*.url',
        ],
        'institution_details' => [
            'institution.name', 'institution.country', 'institution.activity_type', 'institution.license_number',
        ],
        'institution_contact' => [
            'institution.representative_name', 'institution.representative_title',
            'institution.phone', 'institution.phone.country', 'institution.phone.number',
            'institution.email', 'institution.website', 'social_links', 'social_links.*', 'social_links.*.platform', 'social_links.*.url',
        ],
    ];

    private const NUMERIC_FIELDS = [
        'individual.birth_date', 'individual.national_id_number', 'individual.primary_phone.number',
        'individual.secondary_phone.number', 'institution.license_number', 'institution.phone.number',
    ];

    private const PERSON_NAME = '/^[\p{L}\p{M}][\p{L}\p{M}\x{200C}\x{200D}\x{2019}\'. -]*$/u';

    private const IDENTIFIER = '/^[\p{L}\p{N}][\p{L}\p{N}\/. -]*$/u';

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $individual = 'profile_type,'.CandidateProfile::TYPE_INDIVIDUAL;
        $institution = 'profile_type,'.CandidateProfile::TYPE_INSTITUTION;
        $countries = Rule::in(Countries::codes());
        $callingCountries = Rule::in(array_keys(PhoneNumbers::callingCodes()));
        $email = ['required', 'string', 'email', 'max:255'];
        $link = ['string', 'max:500', 'url:http,https', $this->safeWebUrl()];

        // The fields of a branch are only validated, and only kept in
        // validated(), for that branch; the branch object itself is refused
        // for the other type.
        $branch = fn (string $condition, array $rules): array => array_map(
            fn (array $fieldRules): array => ["exclude_unless:{$condition}", ...$fieldRules],
            $rules,
        );

        return [
            'profile_type' => ['required', 'string', Rule::in([CandidateProfile::TYPE_INDIVIDUAL, CandidateProfile::TYPE_INSTITUTION])],

            'individual' => ["required_if:{$individual}", "prohibited_unless:{$individual}", 'array'],
            ...$branch($individual, [
                'individual.first_name' => ['required', 'string', 'max:80', 'regex:'.self::PERSON_NAME],
                'individual.middle_name' => ['nullable', 'string', 'max:80', 'regex:'.self::PERSON_NAME],
                'individual.last_name' => ['required', 'string', 'max:80', 'regex:'.self::PERSON_NAME],
                'individual.birth_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:'.now('Asia/Damascus')->toDateString()],
                'individual.nationality' => ['required', 'string', $countries],
                'individual.gender' => ['required', 'string', Rule::in(ApplicantIndividualDetail::GENDERS)],
                'individual.national_id_number' => ['required', 'string', 'max:50', 'regex:'.self::IDENTIFIER],
                'individual.residence_country' => ['required', 'string', $countries],
                'individual.address' => ['required', 'string', 'max:500'],
                'individual.primary_phone' => ['required', 'array'],
                'individual.primary_phone.country' => ['required', 'string', $callingCountries],
                'individual.primary_phone.number' => ['required', 'string', 'max:32', $this->phoneNumber('individual.primary_phone')],
                'individual.secondary_phone' => ['nullable', 'array'],
                'individual.secondary_phone.country' => ['nullable', 'required_with:individual.secondary_phone.number', 'string', $callingCountries],
                'individual.secondary_phone.number' => ['nullable', 'string', 'max:32', $this->phoneNumber('individual.secondary_phone')],
                'individual.contact_email' => $email,
            ]),

            'institution' => ["required_if:{$institution}", "prohibited_unless:{$institution}", 'array'],
            ...$branch($institution, [
                'institution.name' => ['required', 'string', 'max:255'],
                'institution.country' => ['required', 'string', $countries],
                'institution.activity_type' => ['required', 'string', 'max:150'],
                'institution.license_number' => ['required', 'string', 'max:50', 'regex:'.self::IDENTIFIER],
                'institution.representative_name' => ['required', 'string', 'max:150', 'regex:'.self::PERSON_NAME],
                'institution.representative_title' => ['required', 'string', 'max:150'],
                'institution.phone' => ['required', 'array'],
                'institution.phone.country' => ['required', 'string', $callingCountries],
                'institution.phone.number' => ['required', 'string', 'max:32', $this->phoneNumber('institution.phone')],
                'institution.email' => $email,
                'institution.website' => ['nullable', ...$link],
            ]),

            'social_links' => ['nullable', 'array', 'max:'.ApplicantSocialLink::MAX_LINKS],
            'social_links.*' => ['array'],
            'social_links.*.platform' => ['required', 'string', 'max:50'],
            'social_links.*.url' => ['required', ...$link],

            'accepted_policies' => ['required', 'array'],
            ...collect(AccountPolicyVersion::REQUIRED_FOR_BASIC_PROFILE)
                ->mapWithKeys(fn (string $code): array => ["accepted_policies.{$code}" => ['required', 'integer']])
                ->all(),
            'declarations' => ['required', 'array'],
            'declarations.data_accuracy' => ['accepted'],
            'declarations.representation' => ["required_if:{$institution}", "accepted_if:{$institution}", "prohibited_unless:{$institution}"],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $choose = fn (string $what): string => "اختر {$what}.";

        return [
            'profile_type.*' => 'اختر نوع المتقدم: فرد أو مؤسسة.',
            'individual.prohibited_unless' => 'لا تُرسل بيانات الفرد مع نوع «مؤسسة».',
            'institution.prohibited_unless' => 'لا تُرسل بيانات المؤسسة مع نوع «فرد».',
            // Laravel uses the first matching key, so specific keys come before patterns.
            'individual.national_id_number.regex' => 'استخدم الحروف والأرقام والفواصل المعتادة (- / .) فقط في رقم الهوية.',
            'institution.license_number.regex' => 'استخدم الحروف والأرقام والفواصل المعتادة (- / .) فقط في رقم الترخيص.',
            '*.*.regex' => 'استخدم الحروف والمسافات وعلامات الأسماء المعتادة فقط في :attribute.',
            'individual.birth_date.required' => 'أدخل تاريخ الميلاد كاملاً: اليوم والشهر والسنة.',
            'individual.birth_date.date_format' => 'أدخل تاريخ ميلاد حقيقياً بالتقويم الميلادي.',
            'individual.birth_date.after_or_equal' => 'أدخل سنة ميلاد من 1900 أو بعدها.',
            'individual.birth_date.before_or_equal' => 'لا يمكن أن يكون تاريخ الميلاد في المستقبل.',
            'individual.nationality.*' => $choose('الجنسية'),
            'individual.gender.*' => $choose('الجنس'),
            'individual.residence_country.*' => $choose('دولة الإقامة'),
            'institution.country.*' => $choose('دولة المؤسسة'),
            '*.*.country.required' => $choose('رمز الدولة'),
            '*.*.country.required_with' => $choose('رمز الدولة'),
            '*.*.country.in' => $choose('رمز دولة من القائمة'),
            '*.email' => 'أدخل :attribute صحيحاً، مثل name@example.com.',
            '*.*.email' => 'أدخل :attribute صحيحاً، مثل name@example.com.',
            'social_links.max' => 'يمكن إضافة 5 روابط على الأكثر.',
            '*.*.url' => 'أدخل رابطاً كاملاً يبدأ بـ https:// أو http://.',
            '*.*.*.url' => 'أدخل رابطاً كاملاً يبدأ بـ https:// أو http://.',
            'accepted_policies.*' => 'وافق على سياسة الخصوصية وشروط الاستخدام المنشورة حالياً.',
            'accepted_policies.*.*' => 'وافق على سياسة الخصوصية وشروط الاستخدام المنشورة حالياً.',
            'declarations.data_accuracy.accepted' => 'أكّد صحة البيانات المقدمة للمتابعة.',
            'declarations.representation.*' => 'أكّد أنك مخوَّل بتمثيل المؤسسة للمتابعة.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'individual.first_name' => 'الاسم الأول',
            'individual.middle_name' => 'الاسم الأوسط',
            'individual.last_name' => 'الاسم الأخير',
            'individual.national_id_number' => 'رقم الهوية الشخصية',
            'individual.address' => 'العنوان',
            'individual.primary_phone.number' => 'رقم التواصل الأساسي',
            'individual.secondary_phone.number' => 'رقم التواصل الاحتياطي',
            'individual.contact_email' => 'البريد الإلكتروني للتواصل',
            'institution.name' => 'اسم المؤسسة',
            'institution.activity_type' => 'نوع النشاط المؤسسي',
            'institution.license_number' => 'رقم الترخيص',
            'institution.representative_name' => 'اسم الشخص المتقدم بالنيابة عن المؤسسة',
            'institution.representative_title' => 'الصفة الوظيفية',
            'institution.phone.number' => 'رقم تواصل المؤسسة',
            'institution.email' => 'البريد الإلكتروني للمؤسسة',
            'institution.website' => 'الموقع الإلكتروني',
            'social_links.*.platform' => 'اسم المنصة',
            'social_links.*.url' => 'الرابط',
        ];
    }

    protected function prepareForValidation(): void
    {
        $input = $this->all();

        foreach (self::NUMERIC_FIELDS as $field) {
            $value = Arr::get($input, $field);
            if (is_string($value)) {
                Arr::set($input, $field, self::asciiDigits($value));
            }
        }

        foreach (['individual.first_name', 'individual.middle_name', 'individual.last_name', 'institution.name', 'institution.representative_name'] as $field) {
            $value = Arr::get($input, $field);
            if (is_string($value)) {
                Arr::set($input, $field, Str::squish($value));
            }
        }

        foreach (['individual.contact_email', 'institution.email'] as $field) {
            $value = Arr::get($input, $field);
            if (is_string($value)) {
                Arr::set($input, $field, trim($value));
            }
        }

        // An empty backup number means none: its preselected code is ignored.
        if (Arr::get($input, 'individual.secondary_phone.number') === null && is_array(Arr::get($input, 'individual'))) {
            Arr::set($input, 'individual.secondary_phone', null);
        }

        $this->replace($input);
    }

    /**
     * Converts Arabic-Indic (٠–٩) and Persian (۰–۹) digits to 0–9.
     */
    public static function asciiDigits(string $value): string
    {
        return strtr($value, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
    }

    /**
     * A valid number for the country chosen beside it (libphonenumber).
     */
    private function phoneNumber(string $group): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($group): void {
            $country = $this->input("{$group}.country");

            if (! PhoneNumbers::supportsRegion($country)) {
                return;
            }

            if (PhoneNumbers::toE164($country, $value) === null) {
                $fail('رقم الهاتف غير صحيح لرمز الدولة المختار. تحقّق من الرقم ورمز الدولة.');
            }
        };
    }

    /**
     * A plain web address: http or https, a host name, and no embedded
     * credentials. Links are stored as text and never fetched by the server.
     */
    private function safeWebUrl(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $parts = is_string($value) ? parse_url($value) : false;

            if ($parts === false
                || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
                || ! str_contains($parts['host'] ?? '', '.')
                || isset($parts['user']) || isset($parts['pass'])
                || preg_match('/[\s\x00-\x1F\x7F]/u', (string) $value) === 1) {
                $fail('أدخل رابطاً كاملاً يبدأ بـ https:// أو http://.');
            }
        };
    }
}
