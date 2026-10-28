<?php

namespace App\Http\Controllers\Admin;

use App\Admin\AuditTrail;
use App\Admin\SystemLogs;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «السجل»: «سجل الحركات» (audit.view) and «الأخطاء التقنية»
 * (logs.view_system), both read-only.
 */
class LogController extends Controller
{
    public function audit(Request $request, AuditTrail $trail): JsonResponse
    {
        $filters = $request->validate(AuditTrail::filterRules(), ['*' => 'قيمة غير صالحة في مرشّحات السجل.']);

        return response()->json($trail->page($filters) + ['actions' => AuditTrail::ACTIONS, 'subjects' => AuditTrail::SUBJECTS]);
    }

    public function system(Request $request, SystemLogs $logs): JsonResponse
    {
        $filters = $request->validate(SystemLogs::filterRules(), ['*' => 'قيمة غير صالحة في مرشّحات السجل.']);
        $sources = $logs->sources();
        $source = $filters['source'] ?? ($sources['sources'][0]['id'] ?? null);

        if ($source === null) {
            return response()->json(['data' => null, 'sources' => $sources['sources'], 'coverage' => $sources['coverage'], 'status' => 'unavailable']);
        }
        $page = $logs->page((string) $source, $filters);
        if ($page === null) {
            return response()->json(['code' => 'log_unavailable', 'message' => 'مصدر السجل هذا غير متاح أو لا يمكن قراءته.', 'sources' => $sources['sources']], 404);
        }

        return response()->json(['data' => $page, 'sources' => $sources['sources'], 'coverage' => $sources['coverage'], 'status' => 'available']);
    }
}
