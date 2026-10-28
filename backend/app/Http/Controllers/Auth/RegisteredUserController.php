<?php

namespace App\Http\Controllers\Auth;

use App\Auth\CandidateRole;
use App\Auth\SafeRedirect;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\AuditLog;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

class RegisteredUserController extends Controller
{
    /**
     * Create a public account, classified «مترشح», and sign it in.
     *
     * The account and its candidate role are created in one transaction.
     * The classification is assigned here, never taken from the request, and
     * it creates no applicant, application, cycle choice or committee
     * membership: those are separate, later steps of the platform.
     */
    public function store(RegisterRequest $request, CandidateRole $candidateRole): JsonResponse
    {
        $user = DB::transaction(function () use ($request, $candidateRole): User {
            $user = new User;
            $user->forceFill([
                'name' => $request->validated('name'),
                'email' => $request->validated('email'),
                'password' => $request->validated('password'),
                'status' => User::STATUS_ACTIVE,
            ])->save();

            $candidateRole->grant($user, CandidateRole::SOURCE_REGISTRATION);
            AuditLog::record('user.registered', (int) $user->getKey(), 'user', (int) $user->getKey(), ['source' => CandidateRole::SOURCE_REGISTRATION]);

            return $user;
        });

        // Sends the verification email. A mail failure must not lose the new
        // account: it is reported, and the account page offers a resend.
        $verificationSent = true;
        try {
            event(new Registered($user));
        } catch (Throwable $exception) {
            report($exception);
            $verificationSent = false;
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'data' => new UserResource($user),
            'redirect' => SafeRedirect::defaultFor($user),
            'verification_email_sent' => $verificationSent,
        ], 201);
    }
}
