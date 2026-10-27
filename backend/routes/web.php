<?php

use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\SpaController;
use Illuminate\Support\Facades\Route;

/*
 * Public pages rendered by the React app. Each path serves public/index.html
 * so a direct visit or a refresh works. This is an explicit list, not a
 * catch-all: anything else keeps Laravel's normal routing and 404s.
 */
Route::get('/', SpaController::class);
Route::get('/login', SpaController::class)->name('login');
Route::get('/register', SpaController::class);
Route::get('/forgot-password', SpaController::class);
Route::get('/reset-password', SpaController::class);
Route::get('/verify-email', SpaController::class);
Route::get('/account', SpaController::class);
Route::get('/dashboard', SpaController::class);
Route::get('/dashboard/basic-information', SpaController::class);
Route::get('/dashboard/nomination-details', SpaController::class);
Route::get('/policies/privacy-policy', SpaController::class);
Route::get('/policies/terms-of-use', SpaController::class);

/*
 * Google sign-in (server-side authorization-code flow with session state).
 * The callback URL must match GOOGLE_REDIRECT_URI and Google Cloud exactly.
 */
Route::middleware('throttle:google')->group(function () {
    Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect'])->name('auth.google.redirect');
    Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('auth.google.callback');
    // Confirms a pending sign-up whose email Google is not authoritative for.
    Route::get('/auth/google/confirm', [GoogleAuthController::class, 'confirm'])->name('auth.google.confirm');
});

// The link in the verification email. Signed-out visitors sign in first.
Route::get('/email/verify/{id}/{hash}', VerifyEmailController::class)
    ->middleware(['auth', 'active', 'throttle:verification-link'])
    ->name('verification.verify');
