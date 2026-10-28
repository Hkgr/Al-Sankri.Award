<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Support\AuditLog;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Failed attempts allowed per email address and IP address per minute.
     */
    private const MAX_ATTEMPTS = 5;

    public const INVALID_CREDENTIALS = 'البريد الإلكتروني أو كلمة المرور غير صحيحة.';

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'redirect' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }

    /**
     * Checks the credentials without signing in. The same message is used
     * for an unknown email, a wrong password and a passwordless (Google)
     * account, and an equivalent hash keeps the timing similar.
     *
     * @throws ValidationException
     */
    public function validateCredentials(): User
    {
        $this->ensureIsNotRateLimited();

        /** @var UserProvider $provider */
        $provider = Auth::guard('web')->getProvider();
        $credentials = ['email' => $this->string('email')->toString(), 'password' => $this->string('password')->toString()];
        $user = $provider->retrieveByCredentials(['email' => $credentials['email']]);

        if ($user === null || $user->getAuthPassword() === null) {
            // Spend the same hashing time as a real check.
            Hash::make($credentials['password']);
        }

        if (! $user instanceof User || ! $provider->validateCredentials($user, $credentials)) {
            RateLimiter::hit($this->throttleKey());
            event(new Failed('web', $user, ['email' => $credentials['email']]));
            // The account, when one uses this email; the email itself is never recorded.
            AuditLog::record('auth.login_failed', null, 'user', $user instanceof User ? (int) $user->getKey() : null, ['method' => 'password']);

            throw ValidationException::withMessages(['email' => self::INVALID_CREDENTIALS]);
        }

        RateLimiter::clear($this->throttleKey());
        $provider->rehashPasswordIfRequired($user, $credentials);

        return $user;
    }

    /**
     * @throws ValidationException
     */
    private function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($this));
        AuditLog::record('auth.login_throttled', null, null, null, ['method' => 'password']);
        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => "محاولات دخول كثيرة. أعد المحاولة بعد {$seconds} ثانية.",
        ])->status(429);
    }

    private function throttleKey(): string
    {
        return Str::transliterate($this->string('email')->toString().'|'.$this->ip());
    }
}
