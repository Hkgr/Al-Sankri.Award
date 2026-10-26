<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class EmailVerificationNotificationController extends Controller
{
    /**
     * Send a new email-verification link to the signed-in account.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json(['status' => 'already_verified', 'message' => 'بريدك الإلكتروني مؤكَّد مسبقاً.']);
        }

        try {
            $user->sendEmailVerificationNotification();
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'تعذّر إرسال رسالة التأكيد الآن. أعد المحاولة بعد قليل.', 'code' => 'mail_failed'], 503);
        }

        return response()->json(['status' => 'sent', 'message' => 'أرسلنا رابط تأكيد جديداً إلى بريدك الإلكتروني. صلاحية الرابط 60 دقيقة.'], 202);
    }
}
