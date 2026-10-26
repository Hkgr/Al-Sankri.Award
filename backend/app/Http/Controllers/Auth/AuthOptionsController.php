<?php

namespace App\Http\Controllers\Auth;

use App\Auth\GoogleAuthConfig;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class AuthOptionsController extends Controller
{
    /**
     * The sign-in options the interface may offer. Only a yes/no flag is
     * exposed: never client IDs, secrets or redirect URIs.
     */
    public function __invoke(GoogleAuthConfig $google): JsonResponse
    {
        return response()->json(['google' => ['available' => $google->available()]]);
    }
}
