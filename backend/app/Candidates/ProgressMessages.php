<?php

namespace App\Candidates;

/** Public stage vocabulary, independent of administrative outcomes. */
class ProgressMessages
{
    public static function for(string $stage, string $locale): array
    {
        $messages = json_decode(file_get_contents(resource_path('award/application-progress.json')), true, flags: JSON_THROW_ON_ERROR);

        return $messages[$locale === 'en' ? 'en' : 'ar'][$stage];
    }
}
