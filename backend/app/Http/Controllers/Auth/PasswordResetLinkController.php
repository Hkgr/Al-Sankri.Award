<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Throwable;

class PasswordResetLinkController extends Controller
{
    public const SENT_MESSAGE = 'إذا كان هذا البريد مسجّلاً لدينا، فستصلك رسالة فيها رابط لتعيين كلمة مرور جديدة خلال دقائق. صلاحية الرابط 60 دقيقة.';

    /**
     * Send a reset link. The answer is the same whether or not the address
     * belongs to an account, so the form cannot be used to find accounts.
     */
    public function store(Request $request): JsonResponse
    {
        $request->merge(['email' => is_string($request->input('email')) ? Str::lower(trim($request->input('email'))) : $request->input('email')]);
        $validated = $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);

        $user = User::query()->where('email', $validated['email'])->first();

        if ($user?->isActive()) {
            try {
                Password::broker()->sendResetLink(['email' => $validated['email']]);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return response()->json(['message' => self::SENT_MESSAGE], 202);
    }
}
