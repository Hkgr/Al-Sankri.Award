<?php

namespace App\Http\Requests\Candidate;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Checks one section of the Stage 2 form with exactly the rules of the
 * confirmation, so the server's checks are reported before the review.
 * Only that section's fields are checked, including the after-validation
 * of places. Nothing is saved.
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
     * The duplicate-place check runs only for the places' own section, so
     * unfinished rows further on never block an earlier section.
     */
    protected function validatesLocations(): bool
    {
        return $this->input('section') === 'dates_locations';
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['section.*' => 'قسم غير معروف.', ...parent::messages()];
    }
}
