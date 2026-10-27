<?php

namespace App\Http\Controllers\Candidate;

use App\Candidates\Documentation;
use App\Candidates\EvidenceInspector;
use App\Candidates\NominationDetails;
use App\Candidates\Submissions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Candidate\DocumentationLinksRequest;
use App\Http\Requests\Candidate\DocumentationUploadRequest;
use App\Models\Application;
use App\Models\CandidateNomination;
use App\Models\DocumentationLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Stage 3 («ملفات العمل وتوثيقه») for the signed-in candidate: the
 * attachments and links of the confirmed Stage 2 journey's application.
 * Writes are accepted only while the application is an unsubmitted draft
 * and the cycle's nomination stage is open; the final check happens inside
 * each write's transaction.
 */
class DocumentationController extends Controller
{
    /**
     * Refusals shared by the writes: [HTTP status, message].
     *
     * @var array<string, array{0: int, 1: string}>
     */
    public const REFUSALS = [
        Documentation::SUBMITTED => [409, 'أُرسلت المشاركة للجنة الحكام، فلم يعد ممكناً إضافة ملفات أو تعديل بياناتها. لم يُحفظ شيء.'],
        Documentation::UNAVAILABLE => [409, 'هذه المرحلة غير متاحة حالياً: باب الترشح لهذه الدورة غير مفتوح. لم يُحفظ شيء.'],
        Documentation::TOO_MANY => [422, 'بلغت الحد الأعلى لعدد الملفات من هذا النوع. احذف ملفاً قبل إضافة غيره.'],
        Documentation::NOT_FOUND => [404, 'لم يُعثر على هذا الملف في مشاركتك.'],
        Documentation::MAIN_DOCUMENT_REPLACE_ONLY => [422, 'وثيقة شرح العمل مطلوبة: استبدلها بملف آخر بدلاً من حذفها.'],
        Documentation::MAIN_DOCUMENT_REQUIRED => [422, 'ارفع وثيقة شرح العمل أولاً؛ هي الملف المطلوب الوحيد في هذه المرحلة.'],
        Documentation::REVISION_CHANGED => [409, 'تغيّرت ملفات هذه المرحلة أو روابطها منذ فتحت الصفحة، ربما من نافذة أخرى. راجعها ثم أكّد من جديد.'],
    ];

    public function __construct(
        private readonly Documentation $documentation,
        private readonly NominationDetails $nominations,
        private readonly Submissions $submissions,
    ) {}

    /**
     * The page's state: the attachments, links, revision and whether they
     * may still change, with the journey key every write sends back.
     */
    public function show(Request $request): JsonResponse
    {
        if ($this->nominations->profileOf($request->user()) === null) {
            return response()->json(['code' => NominationDetails::BASIC_INFORMATION_REQUIRED, 'message' => 'أكمل البيانات الأساسية (المرحلة 1) وأكّد حفظها أولاً.'], 409);
        }

        // documentation/journey: the exact journey the page named (reconciling
        // an upload whose answer was lost); otherwise the one to show now.
        $journey = $request->attributes->get('candidate_journey') ?? $this->documentation->journeyOf($request->user());

        if ($journey === null) {
            return response()->json(['data' => null, 'availability' => ['status' => 'nomination_details_required']]);
        }

        return response()->json($this->statePayload($journey));
    }

    /**
     * Stores one file.
     */
    public function upload(DocumentationUploadRequest $request): JsonResponse
    {
        $journey = $this->journey($request);
        [$outcome, $result] = $this->documentation->upload(
            $request->user(), $journey, (string) $request->validated('kind'), $request->file('file'),
            $request->validated('title'), (string) $request->validated('operation_id'),
        );

        return match ($outcome) {
            Documentation::STORED, Documentation::ALREADY_STORED => response()->json([
                'code' => $outcome,
                'operation_id' => $request->validated('operation_id'),
            ] + $this->statePayload($journey->refresh()), $outcome === Documentation::STORED ? 201 : 200),
            Documentation::INVALID_FILE => response()->json(['message' => $result, 'errors' => ['file' => [$result]]], 422),
            default => $this->refusal($outcome),
        };
    }

    /**
     * Removes a supporting document or a video.
     */
    public function remove(Request $request): JsonResponse
    {
        $validated = $request->validate(['attachment' => ['required', 'integer']], ['attachment.*' => 'اختر ملفاً لحذفه.']);
        $journey = $this->journey($request);
        [$outcome] = $this->documentation->remove($request->user(), $journey, (int) $validated['attachment']);

        return $outcome === Documentation::SAVED ? response()->json($this->statePayload($journey->refresh())) : $this->refusal($outcome);
    }

    /**
     * Saves the whole list of documentation links.
     */
    public function links(DocumentationLinksRequest $request): JsonResponse
    {
        $journey = $this->journey($request);
        $outcome = $this->documentation->saveLinks($request->user(), $journey, $request->links());

        return $outcome === Documentation::SAVED ? response()->json($this->statePayload($journey->refresh())) : $this->refusal($outcome);
    }

    /**
     * Confirms Stage 3 for the revision the page showed.
     */
    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate(['revision' => ['required', 'integer', 'min:0']], ['revision.*' => 'أعد تحميل الصفحة ثم حاول مرة أخرى.']);
        $journey = $this->journey($request);
        $outcome = $this->documentation->confirm($request->user(), $journey, (int) $validated['revision']);

        if ($outcome === Documentation::CONFIRMED) {
            return response()->json($this->statePayload($journey->refresh()));
        }

        return $outcome === Documentation::REVISION_CHANGED
            ? response()->json(['code' => $outcome, 'message' => self::REFUSALS[$outcome][1]] + $this->statePayload($journey->refresh()), 409)
            : $this->refusal($outcome);
    }

    /**
     * The owner's file: documents as a download, videos inline for native
     * playback (byte ranges supported). Private, never cached, never sniffed.
     */
    public function download(Request $request, string $attachment): BinaryFileResponse|JsonResponse
    {
        $link = ctype_digit($attachment) ? $this->documentation->attachment($this->journey($request), (int) $attachment) : null;

        if ($link === null) {
            return $this->refusal(Documentation::NOT_FOUND);
        }

        $file = $link->file;
        $video = str_starts_with($file->detected_mime_type, 'video/');
        $response = new BinaryFileResponse(Storage::disk(Documentation::DISK)->path($file->storage_path), 200, [
            'Content-Type' => $file->detected_mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; media-src 'self'; sandbox",
        ], false);
        $response->setContentDisposition(
            $video ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $file->original_name,
            Str::ascii($file->original_name) !== '' ? (string) preg_replace('/[^A-Za-z0-9._-]/', '_', Str::ascii($file->original_name)) : 'file',
        );

        return $response;
    }

    private function journey(Request $request): CandidateNomination
    {
        return $request->attributes->get('candidate_journey');
    }

    /**
     * @return array<string, mixed>
     */
    private function statePayload(CandidateNomination $journey): array
    {
        return [
            'data' => $this->documentation->state($journey) + [
                'submission' => $this->submissions->receipt(Application::query()->findOrFail($journey->application_id)),
            ],
            'journey_key' => $this->documentation->journeyKey($journey),
            'limits' => [
                'document_max_bytes' => EvidenceInspector::DOCUMENT_MAX_BYTES,
                'video_max_bytes' => EvidenceInspector::VIDEO_MAX_BYTES,
                'max_supporting' => Documentation::KINDS['supporting']['max'],
                'max_videos' => Documentation::KINDS['video']['max'],
                'max_links' => DocumentationLink::MAX_ROWS,
            ],
        ];
    }

    public function refusal(string $outcome): JsonResponse
    {
        [$status, $message] = self::REFUSALS[$outcome] ?? [409, 'تعذّر تنفيذ الطلب. أعد تحميل الصفحة ثم حاول مرة أخرى.'];

        return response()->json(['code' => $outcome, 'message' => $message], $status);
    }
}
