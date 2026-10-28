<?php

namespace App\Http\Requests\Candidate;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Checks one section of the Stage 2 form with exactly the rules of the
 * confirmation, so the server's checks are reported before the review.
 * Only that section's fields are checked. Nothing is saved. A retired
 * section or field is refused like the confirmation refuses it.
 */
class NominationDetailsSectionRequest extends NominationDetailsRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $section = $this->input('section');
        $fields = is_string($section) ? (self::SECTIONS[$section] ?? []) : [];

        return [
            'section' => ['required', 'string', Rule::in(array_keys(self::SECTIONS))],
            ...array_intersect_key(parent::rules(), array_flip($fields)),
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
