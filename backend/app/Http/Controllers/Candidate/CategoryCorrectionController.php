<?php

namespace App\Http\Controllers\Candidate;

use App\Candidates\Documentation;
use App\Http\Controllers\Controller;
use App\Http\Resources\NominationDetailsResource;
use App\Support\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CategoryCorrectionController extends Controller
{
    public function store(Request $request, Documentation $documentation): JsonResponse
    {
        $data = $request->validate(['subcategory' => ['required', 'string', 'max:80']]);

        return DB::transaction(function () use ($request, $documentation, $data): JsonResponse {
            [$refusal, $journey, $application] = $documentation->lockForChange($request->user(), $request->attributes->get('candidate_journey'));
            if ($refusal !== null) {
                return app(DocumentationController::class)->refusal($refusal);
            }
            if ($application->taxonomy_review_required) {
                $category = DB::table('cycle_domain_subcategories')->where('cycle_domain_id', $application->cycle_domain_id)
                    ->where('code', $data['subcategory'])->where('is_active', true)->lockForUpdate()->first();
                abort_if($category === null, 422);
                $application->forceFill(['subcategory_id' => $category->id, 'taxonomy_review_required' => false])->save();
                AuditLog::record('application.category_corrected', (int) $request->user()->id, 'application', (int) $application->id, ['subcategory_id' => (int) $category->id], (int) $application->cycle_id);
            }

            return response()->json(['data' => new NominationDetailsResource($journey->refresh())]);
        });
    }
}
