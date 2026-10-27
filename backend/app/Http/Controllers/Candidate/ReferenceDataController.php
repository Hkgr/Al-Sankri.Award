<?php

namespace App\Http\Controllers\Candidate;

use App\Http\Controllers\Controller;
use App\Support\Countries;
use App\Support\PhoneNumbers;
use Illuminate\Http\JsonResponse;

class ReferenceDataController extends Controller
{
    /**
     * Country names (ISO 3166-1, Arabic) and international calling codes for
     * the form's lists, from the same data the server validates against.
     */
    public function __invoke(): JsonResponse
    {
        $callingCodes = PhoneNumbers::callingCodes();

        return response()->json([
            'countries' => collect(Countries::NAMES)
                ->map(fn (string $name, string $code): array => [
                    'code' => $code,
                    'name' => $name,
                    'calling_code' => $callingCodes[$code] ?? null,
                ])
                ->values()
                ->all(),
        ]);
    }
}
