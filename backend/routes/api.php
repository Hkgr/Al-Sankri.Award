<?php

use App\Admin\AdminAccess;
use App\Http\Controllers\Account\GoogleLinkController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\AccountPolicyController;
use App\Http\Controllers\Admin\AdminContextController;
use App\Http\Controllers\Admin\ApplicationReviewsController;
use App\Http\Controllers\Admin\ApplicationsController;
use App\Http\Controllers\Admin\CommitteesController;
use App\Http\Controllers\Admin\ExportsController;
use App\Http\Controllers\Admin\LogController;
use App\Http\Controllers\Admin\UsersController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\AuthOptionsController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Candidate\BasicProfileController;
use App\Http\Controllers\Candidate\CandidateOverviewController;
use App\Http\Controllers\Candidate\DocumentationController;
use App\Http\Controllers\Candidate\NominationDetailsController;
use App\Http\Controllers\Candidate\ReferenceDataController;
use App\Http\Controllers\Candidate\SubmissionController;
use App\Http\Controllers\Judge\ProjectsController;
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
    // Reconciling a save whose answer was lost: that form's journey only.
    Route::get('nomination-details/journey', [NominationDetailsController::class, 'journey'])->middleware('candidate.context');
    Route::post('nomination-details/check', [NominationDetailsController::class, 'check'])
        ->middleware(['throttle:candidate-check', 'candidate.context', 'nomination.gate']);
    Route::post('nomination-details', [NominationDetailsController::class, 'store'])
        ->middleware(['throttle:candidate-confirm', 'candidate.context', 'nomination.gate']);

    // Stage 3 and the final submission. Writes and file reads also pass
    // documentation.journey: the page's journey_key must name this account's
    // confirmed Stage 2 journey. Every write rechecks, inside its
    // transaction, that the application is still an unsubmitted draft.
    Route::get('documentation', [DocumentationController::class, 'show'])->middleware('candidate.context');
    // Reconciling an upload or a submission whose answer was lost: that
    // page's journey only (journey_key in the query), even once its cycle
    // closed. Never the journey of another cycle.
    Route::get('documentation/journey', [DocumentationController::class, 'show'])->middleware(['candidate.context', 'documentation.journey']);
    Route::post('documentation/files', [DocumentationController::class, 'upload'])
        ->middleware(['throttle:candidate-upload', 'candidate.context', 'documentation.journey']);
    Route::post('documentation/files/remove', [DocumentationController::class, 'remove'])
        ->middleware(['throttle:candidate-check', 'candidate.context', 'documentation.journey']);
    Route::post('documentation/links', [DocumentationController::class, 'links'])
        ->middleware(['throttle:candidate-check', 'candidate.context', 'documentation.journey']);
    Route::post('documentation/confirm', [DocumentationController::class, 'confirm'])
        ->middleware(['throttle:candidate-confirm', 'candidate.context', 'documentation.journey']);
    // The owner's file (a link or a <video> cannot send headers: the account
    // context and journey key come in the query).
    Route::get('documentation/files/{attachment}', [DocumentationController::class, 'download'])
        ->middleware(['candidate.context:query', 'documentation.journey']);

    Route::get('review', [SubmissionController::class, 'show'])->middleware('candidate.context');
    Route::get('review/journey', [SubmissionController::class, 'show'])->middleware(['candidate.context', 'documentation.journey']);
    Route::post('submission', [SubmissionController::class, 'store'])
        ->middleware(['throttle:candidate-confirm', 'candidate.context', 'documentation.journey']);
});

/*
 * The judge portal. A current `judge` grant (given by appointing the account
 * in «لجان الحكام») is required; the role carries no permission of its own.
 * Reads and the file download carry the page's account_context. Sending a
 * mark finalizes that judge's evaluation for one assigned project.
 */
Route::middleware(['no-store', 'auth:sanctum', 'active', 'judge'])->prefix('judge')->group(function () {
    Route::middleware('account.context')->group(function () {
        Route::get('projects', [ProjectsController::class, 'index']);
        Route::get('projects/{application}', [ProjectsController::class, 'show'])->whereNumber('application');
    });
    Route::get('projects/{application}/files/{attachment}', [ProjectsController::class, 'file'])
        ->middleware('account.context:query')
        ->whereNumber(['application', 'attachment']);
    Route::post('projects/{application}/evaluation', [ProjectsController::class, 'send'])
        ->middleware(['account.context', 'throttle:admin-writes'])
        ->whereNumber('application');
});

/*
 * The administration dashboard. Every endpoint needs an active account
 * holding the named permission through a current role grant; candidates
 * and judges are refused. Writes carry the page's account_context.
 */
Route::middleware(['no-store', 'auth:sanctum', 'active', 'admin'])->prefix('admin')->group(function () {
    Route::get('context', AdminContextController::class);

    // «المستخدمون»: read-only list of every registered account.
    Route::get('users', [UsersController::class, 'index'])->middleware('admin:'.AdminAccess::VIEW_USERS);

    // «السجل»: the activity log and sanitized technical-log summaries, read-only.
    Route::get('log/audit', [LogController::class, 'audit'])->middleware('admin:'.AdminAccess::VIEW_AUDIT);
    Route::get('log/system', [LogController::class, 'system'])->middleware('admin:'.AdminAccess::VIEW_SYSTEM_LOGS);

    // Table exports: reports.export here, the table's own viewing permission in the controller.
    Route::get('exports/{table}', [ExportsController::class, 'table'])
        ->where('table', '[a-z-]+')
        ->middleware(['admin:'.AdminAccess::EXPORT_REPORTS, 'account.context', 'throttle:admin-exports']);

    Route::middleware('admin:'.AdminAccess::VIEW_APPLICATIONS)->group(function () {
        Route::get('applications', [ApplicationsController::class, 'index']);
        Route::get('applications/{application}', [ApplicationsController::class, 'show'])->whereNumber('application');
        // «بطاقة الترشح» (also needs reports.export, checked in the controller).
        Route::get('applications/{application}/card', [ExportsController::class, 'applicationCard'])->whereNumber('application')
            ->middleware(['account.context', 'throttle:admin-exports']);
        Route::get('applications/{application}/files/{attachment}', [ApplicationsController::class, 'file'])
            ->whereNumber(['application', 'attachment']);

        // The administrative review gate: refer to the committee, or exclude and archive.
        Route::middleware(['admin:'.AdminAccess::REVIEW_APPLICATIONS, 'account.context', 'throttle:admin-writes'])->group(function () {
            Route::post('applications/{application}/review/refer', [ApplicationReviewsController::class, 'refer'])->whereNumber('application');
            Route::post('applications/{application}/review/exclude', [ApplicationReviewsController::class, 'exclude'])->whereNumber('application');
        });
    });

    Route::middleware('admin:'.AdminAccess::MANAGE_COMMITTEES)->prefix('cycles/{cycle}')->whereNumber('cycle')->group(function () {
        Route::get('committees', [CommitteesController::class, 'index']);
        Route::middleware(['account.context', 'throttle:admin-writes'])->group(function () {
            Route::post('committees', [CommitteesController::class, 'store']);
            Route::post('judges/lookup', [CommitteesController::class, 'lookup']);
            Route::post('judges', [CommitteesController::class, 'appoint']);
            Route::post('committees/{domain}/approve', [CommitteesController::class, 'approve']);
            Route::post('committees/{domain}/return-to-draft', [CommitteesController::class, 'returnToDraft']);
            Route::post('committees/{domain}/members/{member}/end', [CommitteesController::class, 'end'])->whereNumber('member');
            Route::post('committees/{domain}/members/{member}/setup-link', [CommitteesController::class, 'setupLink'])->whereNumber('member');
        });
    });
});
