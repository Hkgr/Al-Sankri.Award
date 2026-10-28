<?php

namespace App\Http\Controllers\Admin;

use App\Admin\AdminAccess;
use App\Admin\AdminCycles;
use App\Admin\RegisteredUsers;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
        $filters = $request->validate([
            'cycle' => ['nullable', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:150'],
            'role' => ['nullable', Rule::in(RegisteredUsers::ROLE_FILTERS)],
            'state' => ['nullable', Rule::in(['active', 'disabled'])],
            'verified' => ['nullable', Rule::in(['verified', 'unverified'])],
            'progress' => ['nullable', Rule::in(RegisteredUsers::PROGRESS)],
            'sort' => ['nullable', Rule::in(array_keys(RegisteredUsers::SORTS))],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', Rule::in(RegisteredUsers::PER_PAGE)],
        ], ['*' => 'قيمة غير صالحة في مرشّحات الجدول.']);

        // The selected cycle, or the default one; an unknown cycle is not replaced by another.
        $cycleId = isset($filters['cycle']) ? (int) $filters['cycle'] : $this->cycles->context()['default_cycle_id'];
        if ($cycleId !== null && ! $this->cycles->exists($cycleId)) {
            return response()->json(['code' => 'cycle_not_found', 'message' => 'الدورة المختارة غير موجودة.'], 404);
        }

        return response()->json($this->users->page($filters, $cycleId, $this->access->can($request->user(), AdminAccess::VIEW_APPLICATIONS)));
    }
}
