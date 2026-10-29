<?php

namespace App\Http\Requests\Candidate;

use App\Candidates\NominationCycles;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Stage 2: one domain and one of its subcategories, and the work: its
 * title, purpose and mission, objectives and impact.
 *
 * Only the listed fields are read (validated()); identifiers, statuses and
 * completion flags are never taken from the request. Text is plain text:
 * control characters are refused, line breaks kept, nothing is truncated.
 *
 * The retired questions (funding, partnerships, places and dates of
 * implementation) are refused as a whole, before validation, with 409
 * `nomination_form_outdated`: an outdated page can never store them, and is
 * told plainly that nothing was saved, rather than having part of what it
 * sent silently dropped. (A 409 reaches an outdated page's general alert;
 * its 422 handling only maps field errors.)
 *
 * Length limits: title 255; purpose and mission 3000; objectives 3000;
 * impact 4000.
 *
 * The usable cycle comes from the nomination.gate middleware, which already
 * refused requests from the wrong account, journey or cycle.
 */
class NominationDetailsRequest extends FormRequest
{
    /**
     * @var array<string, list<string>>
     */
    public const SECTIONS = [
        'domain' => ['domain', 'subcategory'],
        'work' => ['title', 'purpose_and_mission', 'objectives', 'impact'],
    ];

    /** Fields and sections of the earlier form, no longer accepted. */
    public const RETIRED_FIELDS = [
        'implementation_state', 'started_on', 'ended_on', 'locations',
        'funding_type', 'funder_name', 'funding_amount', 'has_partnerships', 'partnerships',
    ];

    public const RETIRED_SECTIONS = ['dates_locations', 'funding'];

    public const FORM_OUTDATED = 'nomination_form_outdated';

    private const MULTILINE = ['purpose_and_mission', 'objectives', 'impact'];

    private const SINGLE_LINE = ['title'];

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
        ];
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
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->refuseRetiredFields();

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

        $this->replace($input);
    }

    /**
     * An earlier version of the form: nothing is checked or saved, and the
     * page is asked to reload, keeping the work's text in view.
     */
    private function refuseRetiredFields(): void
    {
        $retired = array_values(array_filter(self::RETIRED_FIELDS, fn (string $field): bool => $this->exists($field)));
        if ($retired === [] && ! in_array($this->input('section'), self::RETIRED_SECTIONS, true)) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'code' => self::FORM_OUTDATED,
            'message' => 'تغيّر نموذج هذه المرحلة: لم تعد أسئلة التمويل والشراكات وأماكن التنفيذ ومواعيده مطلوبة. لم يُحفظ شيء، وما كتبته ما زال في هذه الصفحة. انسخ نصوص العمل إن أردت، ثم أعد تحميل الصفحة وأكمل النموذج الجديد.',
            'retired_fields' => $retired,
        ], 409));
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
}
