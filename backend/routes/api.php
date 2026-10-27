<?php

use App\Http\Controllers\Account\GoogleLinkController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\AccountPolicyController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\AuthOptionsController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Candidate\BasicProfileController;
use App\Http\Controllers\Candidate\CandidateOverviewController;
use App\Http\Controllers\Candidate\NominationDetailsController;
use App\Http\Controllers\Candidate\ReferenceDataController;
use Illuminate\Support\Facades\Route;

/*
 * Session (Sanctum SPA) authentication for the React app. Requests from the
 * site itself carry the session cookie and must send the X-XSRF-TOKEN header
 * obtained from GET /sanctum/csrf-cookie.
 */
Route::prefix('auth')->group(function () {
    Route::get('options', AuthOptionsController::class);

    Route::middleware('throttle:auth')->group(function () {
        Route::post('register', [RegisteredUserController::class, 'store']);
        Route::post('login', [AuthenticatedSessionController::class, 'store']);
        Route::post('reset-password', [NewPasswordController::class, 'store']);
    });

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])->middleware('throttle:password-reset');
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->middleware('auth:sanctum');
});

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('user', AccountController::class);
    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:verification');
    Route::post('account/google/link', [GoogleLinkController::class, 'store'])
        ->middleware('throttle:password-confirmation');
});

// Published account policies (privacy policy, terms of use) are public.
Route::get('policies/{policy}', AccountPolicyController::class)
    ->whereIn('policy', array_keys(AccountPolicyController::SLUGS));

/*
 * The candidate dashboard. Every endpoint concerns the signed-in account
 * only (no identifiers in URLs), needs an active account holding the
 * «مترشح» classification, and is never cached.
 */
Route::middleware(['no-store', 'auth:sanctum', 'active', 'candidate'])->prefix('candidate')->group(function () {
    Route::get('reference-data', ReferenceDataController::class);

    // Everything that reads or writes this account's candidate state carries
    // the account context of the page that asks (App\Candidates\AccountContext):
    // the X-Candidate-Context header on reads, the account_context field on writes.
    Route::get('overview', CandidateOverviewController::class)->middleware('candidate.context');
    Route::get('basic-profile', [BasicProfileController::class, 'show'])->middleware('candidate.context');
    Route::post('basic-profile/check', [BasicProfileController::class, 'check'])->middleware(['throttle:candidate-check', 'candidate.context']);
    Route::post('basic-profile', [BasicProfileController::class, 'store'])->middleware(['throttle:candidate-confirm', 'candidate.context']);

    // Stage 2. Writes also pass nomination.gate: Stage 1 confirmed, the
    // stage usable now, and the form's journey_key still current.
    Route::get('nomination-details', [NominationDetailsController::class, 'show'])->middleware('candidate.context');
    Route::post('nomination-details/check', [NominationDetailsController::class, 'check'])
        ->middleware(['throttle:candidate-check', 'candidate.context', 'nomination.gate']);
    Route::post('nomination-details', [NominationDetailsController::class, 'store'])
        ->middleware(['throttle:candidate-confirm', 'candidate.context', 'nomination.gate']);
});
