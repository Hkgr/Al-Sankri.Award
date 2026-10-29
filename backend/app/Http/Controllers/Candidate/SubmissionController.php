<?php

namespace App\Http\Controllers\Candidate;

use App\Candidates\BasicProfiles;
use App\Candidates\Documentation;
use App\Candidates\NominationDetails;
use App\Candidates\Submissions;
use App\Http\Controllers\Controller;
use App\Http\Resources\BasicProfileResource;
use App\Http\Resources\NominationDetailsResource;
use App\Models\Application;
use App\Models\CandidateNomination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The read-only review of the whole participation and its final submission
 * («إرسال ملف الترشح»). Submitting sends the application to award
 * administration for initial review (classification `pending`); it starts
 * no judging.
 */
class SubmissionController extends Controller
{
    public function __construct(
        private readonly BasicProfiles $profiles,
        private readonly NominationDetails $nominations,
        private readonly Documentation $documentation,
        private readonly Submissions $submissions,
    ) {}

    /**
     * Everything the candidate is about to send (or has sent): Stage 1,
     * Stage 2 and Stage 3 as stored, the documentation revision the review
     * shows, and the receipt once submitted.
     *
     * On `review/journey` (reconciling a submission whose answer was lost)
     * documentation.journey has already resolved the exact journey the page
     * named, even after its cycle closed; another cycle's participation is
     * never shown in its place.
     */
    public function show(Request $request): JsonResponse
    {
        $profile = $this->profiles->profileOf($request->user());

        if ($profile === null) {
            return response()->json(['code' => NominationDetails::BASIC_INFORMATION_REQUIRED, 'message' => 'أكمل البيانات الأساسية (المرحلة 1) وأكّد حفظها أولاً.'], 409);
        }

        $journey = $request->attributes->get('candidate_journey') ?? $this->documentation->journeyOf($request->user());

        if ($journey === null) {
            return response()->json(['data' => null, 'availability' => ['status' => 'nomination_details_required']]);
        }

        return response()->json([
            'data' => [
                'basic_information' => new BasicProfileResource($profile),
                'nomination_details' => new NominationDetailsResource($journey),
                'documentation' => $this->documentation->state($journey),
                'submission' => $this->submissions->receipt(Application::query()->findOrFail($journey->application_id)),
            ],
            'journey_key' => $this->documentation->journeyKey($journey),
        ]);
    }

    /**
     * Submits the reviewed participation, once. A retry returns the same
     * receipt (200 already_submitted).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(['reviewed_revision' => ['required', 'integer', 'min:0']], ['reviewed_revision.*' => 'أعد تحميل صفحة المراجعة ثم حاول مرة أخرى.']);
        /** @var CandidateNomination $journey */
        $journey = $request->attributes->get('candidate_journey');
        [$outcome, $receipt] = $this->submissions->submit($request->user(), $journey, (int) $validated['reviewed_revision']);

        return match ($outcome) {
            Submissions::SUBMITTED => response()->json(['code' => $outcome, 'data' => $receipt, 'message' => 'تم إرسال ملف الترشح إلى إدارة الجائزة للمراجعة الأولية.'], 201),
            Submissions::ALREADY_SUBMITTED => response()->json(['code' => $outcome, 'data' => $receipt, 'message' => 'أُرسلت هذه المشاركة من قبل، ولم يُرسل شيء مرة ثانية.']),
            Submissions::REVIEW_OUTDATED => response()->json([
                'code' => $outcome,
                'message' => 'تغيّرت ملفات العمل أو روابطه منذ فتحت صفحة المراجعة، أو لم يُؤكَّد حفظ المرحلة 3 لهذه النسخة. لم يُرسل شيء؛ راجع المشاركة من جديد.',
            ], 409),
            Submissions::COMMITTEE_MEMBER => response()->json([
                'code' => $outcome,
                'message' => 'لا يمكن إرسال هذه المشاركة: هذا الحساب حكَم أو عضو في لجنة من لجان هذه الدورة (التحكيم أو النزاهة أو الأمناء)، ولا يحق لأعضاء اللجان الترشح فيها. لم يُرسل شيء. للاستفسار تواصل مع إدارة الجائزة.',
            ], 403),
            Submissions::AWARD_ADMIN => response()->json([
                'code' => $outcome,
                'message' => 'لا يمكن إرسال هذه المشاركة: هذا الحساب يملك صلاحية إدارة الجائزة، ولا يحق لإدارة الجائزة الترشح. لم يُرسل شيء.',
            ], 403),
            default => app(DocumentationController::class)->refusal($outcome),
        };
    }
}
