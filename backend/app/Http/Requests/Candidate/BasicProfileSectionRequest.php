<?php

namespace App\Http\Requests\Candidate;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Checks one internal section of the stage 1 form (for example the
 * individual's identity) with exactly the rules of the final confirmation,
 * so the server's phone, country and format checks are reported before the
 * review step. Nothing is saved.
 */
class BasicProfileSectionRequest extends BasicProfileRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $section = $this->input('section');
        $fields = is_string($section) ? (self::SECTIONS[$section] ?? []) : [];
        $all = parent::rules();

        return [
            'section' => ['required', 'string', Rule::in(array_keys(self::SECTIONS)), function (string $attribute, mixed $value, Closure $fail): void {
                // individual_* sections belong to individuals, institution_* to institutions.
                if (is_string($value) && ! str_starts_with($value, ((string) $this->input('profile_type')).'_')) {
                    $fail('هذا القسم لا يخص نوع المتقدم المختار.');
                }
            }],
            'profile_type' => $all['profile_type'],
            ...array_intersect_key($all, array_flip($fields)),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['section.*' => 'قسم غير معروف.', ...parent::messages()];
    }
}
