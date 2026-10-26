<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Throwable;

class RegisteredUserController extends Controller
{
    /**
     * Create a basic account and sign it in.
     *
     * The account gets no role, committee membership, applicant or
     * application: those are separate, later steps of the platform.
     */
    public function store(RegisterRequest $request): JsonResponse
    {
        $user = new User;
        $user->forceFill([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'password' => $request->validated('password'),
            'status' => User::STATUS_ACTIVE,
        ])->save();

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
            'redirect' => '/account',
            'verification_email_sent' => $verificationSent,
        ], 201);
    }
}
