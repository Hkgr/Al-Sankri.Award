<?php

namespace App\Http\Controllers\Admin;

use App\Admin\AccountRemoval;
use App\Admin\RefusedChange;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountRemovalController extends Controller
{
    public function __construct(private readonly AccountRemoval $removal) {}

    public function preview(Request $request, int $user): JsonResponse
    {
        try {
            return response()->json(['data' => $this->removal->preview($request->user(), $user)]);
        } catch (RefusedChange $failure) {
            return $this->refusal($failure);
        }
    }

    public function store(Request $request, int $user): JsonResponse
    {
        $input = $request->validate([
            'operation_id' => ['required', 'uuid'], 'mode' => ['required', 'in:retain,purge'],
            'fingerprint' => ['required', 'string', 'size:64'], 'confirmation' => ['nullable', 'string', 'max:255'],
        ]);
        try {
            return response()->json([
                'data' => $this->removal->remove($request->user(), $user, $input),
                'message' => 'تم حذف الحساب وإبطال الوصول إليه وفق الخيار المحدد.',
            ]);
        } catch (RefusedChange $failure) {
            return $this->refusal($failure);
        }
    }

    private function refusal(RefusedChange $failure): JsonResponse
    {
        return response()->json([
            'code' => $failure->outcome,
            'message' => $failure->outcome === 'removal_target_missing' ? 'الحساب غير موجود.' : 'تغيّر نطاق الحذف أو حالة الحساب. أعد مراجعة الأثر ثم أكّد من جديد.',
        ], $failure->outcome === 'removal_target_missing' ? 404 : 409);
    }
}
