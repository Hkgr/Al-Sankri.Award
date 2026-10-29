<?php

namespace App\Http\Controllers\Account;

use App\Auth\RoleExclusivity;
use App\Auth\SafeRedirect;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A current judge registers as a participant. The judgeship is cancelled
 * in the same step; the account cannot keep both.
 */
class ParticipantRegistrationController extends Controller
{
    public function store(Request $request, RoleExclusivity $roles): JsonResponse
    {
        $user = $request->user();
        $result = $roles->registerAsParticipant($user);

        if ($result === RoleExclusivity::NOT_JUDGE) {
            return response()->json([
                'message' => 'هذا الحساب ليس حكماً حالياً، فلم يتغيّر شيء.',
                'code' => RoleExclusivity::NOT_JUDGE,
            ], 409);
        }

        return response()->json([
            'data' => new UserResource($user),
            'redirect' => SafeRedirect::DASHBOARD,
        ]);
    }
}
