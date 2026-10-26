<?php

use App\Http\Controllers\Account\GoogleLinkController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\AuthOptionsController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
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
