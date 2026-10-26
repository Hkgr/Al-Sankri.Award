<?php

namespace App\Providers;

use App\Auth\AccountEmail;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\HttpFoundation\Response;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Password::defaults(fn () => Password::min(8)->letters()->numbers());

        $this->configureAccountEmails();
        $this->configureRateLimiting();
    }

    /**
     * Verification and reset links point at this site's own pages, are signed
     * relative to the site (so a proxy cannot break the signature) and are
     * sent as short Arabic, right-to-left emails.
     */
    private function configureAccountEmails(): void
    {
        VerifyEmail::createUrlUsing(fn (User $user): string => url(URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes((int) config('auth.verification.expire', 60)),
            ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())],
            absolute: false,
        )));

        VerifyEmail::toMailUsing(fn (User $user, string $url): MailMessage => AccountEmail::message(
            subject: 'تأكيد بريدك الإلكتروني',
            heading: 'أكّد بريدك الإلكتروني',
            intro: 'أنشأت حساباً في موقع '.AccountEmail::AWARD_NAME.'. اضغط الزر أدناه لتأكيد أن هذا البريد الإلكتروني لك.',
            action: 'تأكيد البريد الإلكتروني',
            url: $url,
            outro: 'صلاحية الرابط 60 دقيقة. إذا لم تنشئ هذا الحساب، فتجاهل هذه الرسالة.',
        ));

        ResetPassword::createUrlUsing(fn (User $user, string $token): string => url('/reset-password').'?'.http_build_query([
            'token' => $token,
            'email' => $user->getEmailForPasswordReset(),
        ]));

        ResetPassword::toMailUsing(fn (User $user, string $token): MailMessage => AccountEmail::message(
            subject: 'تعيين كلمة مرور جديدة',
            heading: 'تعيين كلمة مرور جديدة',
            intro: 'وصلنا طلب لتعيين كلمة مرور جديدة لحسابك في موقع '.AccountEmail::AWARD_NAME.'.',
            action: 'تعيين كلمة المرور',
            url: url('/reset-password').'?'.http_build_query(['token' => $token, 'email' => $user->getEmailForPasswordReset()]),
            outro: 'صلاحية الرابط 60 دقيقة. إذا لم تطلب ذلك، فتجاهل هذه الرسالة وستبقى كلمة مرورك كما هي.',
        ));
    }

    private function configureRateLimiting(): void
    {
        $tooMany = fn (Request $request, array $headers): Response => $request->expectsJson()
            ? response()->json(['message' => 'محاولات كثيرة. انتظر دقيقة ثم أعد المحاولة.', 'code' => 'too_many_attempts'], 429, $headers)
            : redirect('/login?google=throttled');

        $email = fn (Request $request): string => Str::lower((string) $request->input('email'));

        // Login, registration and password resets, per IP address. Login also
        // has its own per-email limit (LoginRequest).
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by($request->ip())->response($tooMany));

        RateLimiter::for('password-reset', fn (Request $request) => [
            Limit::perMinute(5)->by('ip:'.$request->ip())->response($tooMany),
            Limit::perMinute(2)->by('email:'.$email($request))->response($tooMany),
        ]);

        RateLimiter::for('verification', fn (Request $request) => Limit::perMinute(3)->by((string) $request->user()?->getKey())->response($tooMany));

        RateLimiter::for('verification-link', fn (Request $request) => Limit::perMinute(6)->by($request->ip())->response($tooMany));

        RateLimiter::for('password-confirmation', fn (Request $request) => Limit::perMinute(5)->by((string) $request->user()?->getKey())->response($tooMany));

        RateLimiter::for('google', fn (Request $request) => Limit::perMinute(20)->by($request->ip())->response($tooMany));
    }
}
