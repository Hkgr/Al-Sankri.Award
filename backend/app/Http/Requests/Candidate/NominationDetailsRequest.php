<?php

namespace App\Http\Requests\Candidate;

use App\Candidates\NominationCycles;
use App\Models\Application;
use App\Models\ApplicationPartnership;
use App\Support\SyrianGovernorates;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Stage 2: one domain and one of its subcategories, the work, its dates
 * and places, the funding declaration and optional partners.
 *
 * Only the listed fields are read (validated()); identifiers, statuses and
 * completion flags are never taken from the request. Fields that do not
 * apply to the chosen answers (an end date for ongoing work, a funder for
 * self-funding, partners when there are none) are refused, not ignored.
 * Text is plain text: control characters are refused, line breaks kept,
 * nothing is truncated.
 *
 * Length limits: title 255; purpose and mission 3000; objectives 3000;
 * impact 4000; locality 150; address 500; funder and partner names 255;
 * partnership description 1000; at most 20 places and 10 partners.
 * Amounts: US dollars, up to 12 digits and 2 decimals, greater than zero.
 *
 * The usable cycle comes from the nomination.gate middleware, which already
 * refused requests from the wrong account, journey or cycle.
 */
class NominationDetailsRequest extends FormRequest
{
    public const MAX_LOCATIONS = 20;

    /**
     * @var array<string, list<string>>
     */
    public const SECTIONS = [
        'domain' => ['domain', 'subcategory'],
        'work' => ['title', 'purpose_and_mission', 'objectives', 'impact'],
        'dates_locations' => [
            'implementation_state', 'started_on', 'ended_on',
            'locations', 'locations.*', 'locations.*.governorate', 'locations.*.locality', 'locations.*.address',
        ],
        'funding' => [
            'funding_type', 'funder_name', 'funding_amount',
            'has_partnerships', 'partnerships', 'partnerships.*', 'partnerships.*.name', 'partnerships.*.contribution',
        ],
    ];

    private const MULTILINE = ['purpose_and_mission', 'objectives', 'impact'];

    private const SINGLE_LINE = ['title', 'funder_name'];

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
        $options = $this->options();
        $today = now('Asia/Damascus')->toDateString();
        $paid = 'funding_type,fully_funded,partially_funded';

        return [
            'domain' => ['required', 'string', Rule::in($options->pluck('code')->all())],
            'subcategory' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail) use ($options): void {
                $domain = $options->firstWhere('code', $this->input('domain'));
                if ($domain !== null && ! in_array($value, array_column($domain['subcategories'], 'code'), true)) {
                    $fail('اختر فئة من فئات المحور المختار.');
                }
            }],

            'title' => ['required', 'string', 'max:255', $this->plainText()],
            'purpose_and_mission' => ['required', 'string', 'max:3000', $this->plainText()],
            'objectives' => ['required', 'string', 'max:3000', $this->plainText()],
            'impact' => ['required', 'string', 'max:4000', $this->plainText()],

            'implementation_state' => ['required', 'string', Rule::in([Application::IMPLEMENTATION_ONGOING, Application::IMPLEMENTATION_COMPLETED])],
            'started_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:'.$today],
            'ended_on' => [
                'required_if:implementation_state,completed', 'prohibited_unless:implementation_state,completed', 'nullable',
                'date_format:Y-m-d', 'after_or_equal:started_on', 'before_or_equal:'.$today,
            ],
            'locations' => ['required', 'array', 'min:1', 'max:'.self::MAX_LOCATIONS],
            'locations.*' => ['array'],
            'locations.*.governorate' => ['required', 'string', Rule::in(SyrianGovernorates::codes())],
            'locations.*.locality' => ['required', 'string', 'max:150', $this->plainText()],
            'locations.*.address' => ['nullable', 'string', 'max:500', $this->plainText()],

            'funding_type' => ['required', 'string', Rule::in(Application::FUNDING_TYPES)],
            'funder_name' => ["required_if:{$paid}", "prohibited_unless:{$paid}", 'nullable', 'string', 'max:255', $this->plainText()],
            'funding_amount' => [
                'required_if:funding_type,fully_funded,partially_funded,self_funded', 'prohibited_if:funding_type,not_needed', 'nullable',
                'string', 'max:20', $this->usdAmount(),
            ],
            'has_partnerships' => ['required', 'boolean'],
            'partnerships' => ['required_if:has_partnerships,true', 'prohibited_if:has_partnerships,false', 'array', 'min:1', 'max:'.ApplicationPartnership::MAX_ROWS],
            'partnerships.*' => ['exclude_unless:has_partnerships,true', 'array'],
            'partnerships.*.name' => ['exclude_unless:has_partnerships,true', 'required', 'string', 'max:255', $this->plainText()],
            'partnerships.*.contribution' => ['exclude_unless:has_partnerships,true', 'nullable', 'string', 'max:1000', $this->plainText()],
        ];
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            // The same place twice, ignoring case and spacing, is refused.
            function (Validator $validator): void {
                $locations = $this->input('locations');
                if (! $this->validatesLocations() || ! is_array($locations)) {
                    return;
                }
                $seen = [];
                foreach (array_values($locations) as $index => $location) {
                    if (! is_array($location)) {
                        continue;
                    }
                    $key = implode('|', array_map(
                        fn (mixed $part): string => mb_strtolower(Str::squish((string) $part)),
                        [$location['governorate'] ?? '', $location['locality'] ?? '', $location['address'] ?? ''],
                    ));
                    if (isset($seen[$key])) {
                        $validator->errors()->add("locations.{$index}.locality", 'مكان التنفيذ هذا مكرّر. احذفه أو غيّر بياناته.');
                    }
                    $seen[$key] = true;
                }
            },
        ];
    }

    /**
     * Whether the places are part of what this request validates: always for
     * the confirmation; for a section check only in its own section.
     */
    protected function validatesLocations(): bool
    {
        return true;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'domain.*' => 'اختر محور الترشح.',
            'subcategory.required' => 'اختر فئة الترشح ضمن المحور.',
            'subcategory.*' => 'اختر فئة من فئات المحور المختار.',
            'implementation_state.*' => 'حدّد إن كان العمل مستمراً أو منتهياً.',
            'started_on.required' => 'أدخل تاريخ بدء العمل كاملاً: اليوم والشهر والسنة.',
            'started_on.date_format' => 'أدخل تاريخ بدء حقيقياً بالتقويم الميلادي.',
            'started_on.after_or_equal' => 'أدخل سنة من 1900 أو بعدها.',
            'started_on.before_or_equal' => 'لا يمكن أن يكون تاريخ بدء العمل في المستقبل.',
            'ended_on.required_if' => 'أدخل تاريخ انتهاء العمل، أو اختر «العمل مستمر».',
            'ended_on.prohibited_unless' => 'لا يُقبل تاريخ انتهاء لعمل مستمر.',
            'ended_on.date_format' => 'أدخل تاريخ انتهاء حقيقياً بالتقويم الميلادي.',
            'ended_on.after_or_equal' => 'لا يمكن أن يسبق تاريخ الانتهاء تاريخ البدء.',
            'ended_on.before_or_equal' => 'لا يمكن أن يكون تاريخ انتهاء العمل في المستقبل.',
            'locations.required' => 'أضف مكان تنفيذ واحداً على الأقل.',
            'locations.min' => 'أضف مكان تنفيذ واحداً على الأقل.',
            'locations.max' => 'يمكن إضافة '.self::MAX_LOCATIONS.' مكاناً على الأكثر.',
            'locations.*.governorate.*' => 'اختر المحافظة.',
            'funding_type.*' => 'اختر الإقرار المالي.',
            'funder_name.required_if' => 'أدخل اسم الجهة الممولة.',
            'funder_name.prohibited_unless' => 'لا يُقبل اسم جهة ممولة مع هذا الإقرار المالي.',
            'funding_amount.required_if' => 'أدخل المبلغ بالدولار الأمريكي.',
            'funding_amount.prohibited_if' => 'لا يُقبل مبلغ مع «لا يحتاج تمويل».',
            'has_partnerships.*' => 'أجب: هل توجد شراكات مرتبطة بالعمل؟',
            'partnerships.required_if' => 'أضف شريكاً واحداً على الأقل، أو اختر «لا».',
            'partnerships.min' => 'أضف شريكاً واحداً على الأقل، أو اختر «لا».',
            'partnerships.prohibited_if' => 'لا تُقبل بيانات شركاء مع الإجابة «لا».',
            'partnerships.max' => 'يمكن إضافة '.ApplicationPartnership::MAX_ROWS.' شركاء على الأكثر.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => 'عنوان العمل',
            'purpose_and_mission' => 'الغاية والرسالة',
            'objectives' => 'الأهداف',
            'impact' => 'الأثر',
            'locations.*.locality' => 'المدينة أو البلدة أو القرية',
            'locations.*.address' => 'العنوان أو تفاصيل المكان',
            'funder_name' => 'اسم الجهة الممولة',
            'funding_amount' => 'المبلغ',
            'partnerships.*.name' => 'اسم الجهة الشريكة',
            'partnerships.*.contribution' => 'طبيعة الشراكة أو المساهمة',
        ];
    }

    protected function prepareForValidation(): void
    {
        $input = $this->all();

        foreach (self::MULTILINE as $field) {
            if (is_string($value = Arr::get($input, $field))) {
                Arr::set($input, $field, trim(str_replace(["\r\n", "\r"], "\n", $value)));
            }
        }

        foreach (self::SINGLE_LINE as $field) {
            if (is_string($value = Arr::get($input, $field))) {
                Arr::set($input, $field, Str::squish($value));
            }
        }

        foreach (['started_on', 'ended_on'] as $field) {
            if (is_string($value = Arr::get($input, $field))) {
                Arr::set($input, $field, BasicProfileRequest::asciiDigits(trim($value)));
            }
        }

        // The Arabic decimal separator (٫) is a decimal point; nothing else
        // is converted, so a grouping comma is refused rather than guessed.
        if (is_string($amount = Arr::get($input, 'funding_amount'))) {
            Arr::set($input, 'funding_amount', str_replace('٫', '.', BasicProfileRequest::asciiDigits(trim($amount))));
        }

        foreach (['locations' => ['locality', 'address'], 'partnerships' => ['name', 'contribution']] as $list => $fields) {
            if (! is_array($rows = Arr::get($input, $list))) {
                continue;
            }
            foreach ($rows as $index => $row) {
                foreach ($fields as $field) {
                    if (is_array($row) && is_string($row[$field] ?? null)) {
                        $rows[$index][$field] = $field === 'contribution' ? trim($row[$field]) : Str::squish($row[$field]);
                        if ($rows[$index][$field] === '') {
                            $rows[$index][$field] = null;
                        }
                    }
                }
            }
            Arr::set($input, $list, $rows);
        }

        $this->replace($input);
    }

    /**
     * The usable cycle's open domains (set by the nomination.gate middleware).
     *
     * @return Collection<int, array{cycle_domain_id: int, code: string, name: string, artwork: string, subcategories: list<array{id: int, code: string, name: string}>}>
     */
    private function options(): Collection
    {
        $cycle = $this->attributes->get('nomination_cycle');

        return $cycle === null ? collect() : app(NominationCycles::class)->options((int) $cycle->id);
    }

    private function plainText(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
                $fail('يحتوي :attribute على رموز تحكّم غير مسموح بها. اكتب نصاً عادياً.');
            }
        };
    }

    /**
     * Digits, an optional point and at most two decimals, above zero.
     * Compared as text: no floating-point conversion anywhere.
     */
    private function usdAmount(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || preg_match('/^\d{1,12}(\.\d{1,2})?$/', $value) !== 1) {
                $fail('اكتب المبلغ بالأرقام، مع نقطة للكسور العشرية عند الحاجة، مثل 1500 أو 1500.50، ودون فواصل للآلاف.');

                return;
            }
            if (preg_match('/[1-9]/', $value) !== 1) {
                $fail('يجب أن يكون المبلغ أكبر من صفر.');
            }
        };
    }
}
