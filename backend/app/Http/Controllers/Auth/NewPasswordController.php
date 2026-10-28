<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use App\Support\AuditLog;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class NewPasswordController extends Controller
{
    /**
     * Set a new password from an emailed reset link.
     *
     * @throws ValidationException
     */
    public function store(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::broker()->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();
                AuditLog::record('auth.password_reset', (int) $user->getKey(), 'user', (int) $user->getKey());

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            // One message for an unknown address and a bad or expired token.
            throw ValidationException::withMessages([
                'token' => 'رابط تعيين كلمة المرور غير صالح أو انتهت صلاحيته. اطلب رابطاً جديداً.',
            ]);
        }

        return response()->json(['message' => 'تم تعيين كلمة المرور الجديدة. يمكنك الآن تسجيل الدخول بها.']);
    }
}
