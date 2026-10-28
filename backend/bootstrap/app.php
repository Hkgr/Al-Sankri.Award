<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureAdministrationPage;
use App\Http\Middleware\EnsureAdministrationPermission;
use App\Http\Middleware\EnsureCandidateAccountContext;
use App\Http\Middleware\EnsureDocumentationJourney;
use App\Http\Middleware\EnsureNominationStageIsOpen;
use App\Http\Middleware\EnsureUserIsCandidate;
use App\Http\Middleware\PreventCaching;
use App\Http\Middleware\UseArabicLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Session cookies and CSRF protection for the React app's API calls.
        $middleware->statefulApi();
        $middleware->web(append: [UseArabicLocale::class]);
        $middleware->api(append: [UseArabicLocale::class]);
        $middleware->alias([
            'active' => EnsureAccountIsActive::class,
            'candidate' => EnsureUserIsCandidate::class,
            'candidate.context' => EnsureCandidateAccountContext::class,
            // The same account-snapshot check, for administration writes.
            'account.context' => EnsureCandidateAccountContext::class,
            'admin' => EnsureAdministrationPermission::class,
            'admin.page' => EnsureAdministrationPage::class,
            'nomination.gate' => EnsureNominationStageIsOpen::class,
            'documentation.journey' => EnsureDocumentationJourney::class,
            'no-store' => PreventCaching::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
