<?php

namespace App\Http\Controllers\Admin;

use App\Admin\AdminAccess;
use App\Admin\AdminCycles;
use App\Admin\RegisteredUsers;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «المستخدمون» (users.view_registered): every registered account, read-only,
 * with its current roles and its candidate progress in the selected cycle.
 * Only the table's fields are returned: no identity numbers, addresses,
 * profile details, funding, narratives, tokens or paths.
 */
class UsersController extends Controller
{
    public function __construct(
        private readonly RegisteredUsers $users,
        private readonly AdminCycles $cycles,
        private readonly AdminAccess $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(RegisteredUsers::filterRules(), ['*' => 'قيمة غير صالحة في مرشّحات الجدول.']);

        $cycleId = $this->users->cycleFor($filters, $this->cycles);
        if ($cycleId === false) {
            return response()->json(['code' => 'cycle_not_found', 'message' => 'الدورة المختارة غير موجودة.'], 404);
        }

        return response()->json($this->users->page($filters, $cycleId, $this->access->can($request->user(), AdminAccess::VIEW_APPLICATIONS)));
    }
}
