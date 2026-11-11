<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LanguagePreferenceController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate(['locale' => ['required', Rule::in(['ar', 'en'])]]);
        DB::transaction(function () use ($request, $data): void {
            $user = User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->isActive(), 403);
            $user->forceFill(['preferred_locale' => $data['locale']])->save();
        });

        return response()->json(['data' => ['preferred_locale' => $data['locale']]]);
    }
}
