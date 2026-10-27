<?php

namespace App\Http\Controllers\Candidate;

use App\Candidates\NominationCycles;
use App\Candidates\NominationDetails;
use App\Http\Controllers\Controller;
use App\Http\Requests\Candidate\NominationDetailsRequest;
use App\Http\Requests\Candidate\NominationDetailsSectionRequest;
use App\Http\Resources\NominationDetailsResource;
use App\Support\SyrianGovernorates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Stage 2 («فئة الترشح وبيانات العمل») for the signed-in candidate. No
 * identifier in any URL. Once confirmed, the stage is read-only: there is no
 * update, replace or delete endpoint, and a second confirmation returns the
 * stored result without changing it.
 */
class NominationDetailsController extends Controller
{
    public function __construct(
        private readonly NominationDetails $details,
        private readonly NominationCycles $cycles,
    ) {}

    /**
     * What the page needs: the confirmed stage, or the options and journey
     * key of the usable cycle, or why the stage is not available.
     */
    public function show(Request $request): JsonResponse
    {
        $profile = $this->details->profileOf($request->user());

        if ($profile === null) {
            return response()->json([
                'code' => NominationDetails::BASIC_INFORMATION_REQUIRED,
                'message' => 'أكمل البيانات الأساسية (المرحلة 1) وأكّد حفظها أولاً.',
            ], 409);
        }

        $current = $this->cycles->current();
        $confirmed = $this->details->confirmedFor($profile, $current['cycle']);

        if ($confirmed !== null) {
            return response()->json(['data' => new NominationDetailsResource($confirmed), 'availability' => ['status' => 'confirmed']]);
        }

        if ($current['status'] !== NominationCycles::AVAILABLE) {
            return response()->json(['data' => null, 'availability' => ['status' => $current['status']]]);
        }

        $cycle = $current['cycle'];

        return response()->json([
            'data' => null,
            'availability' => ['status' => NominationCycles::AVAILABLE],
            'cycle' => ['code' => $cycle->code, 'name' => $cycle->name_ar],
            'journey_key' => $this->cycles->journeyKey($profile, (int) $cycle->id),
            'options' => [
                'domains' => $this->cycles->options((int) $cycle->id)
                    ->map(fn (array $domain): array => [
                        'code' => $domain['code'],
                        'name' => $domain['name'],
                        'artwork' => $domain['artwork'],
                        'subcategories' => array_map(fn (array $subcategory): array => ['code' => $subcategory['code'], 'name' => $subcategory['name']], $domain['subcategories']),
                    ])
                    ->all(),
                'country' => ['code' => $this->cycles->country($cycle), 'name' => 'سوريا'],
                'governorates' => collect(SyrianGovernorates::NAMES)->map(fn (string $name, string $code): array => ['code' => $code, 'name' => $name])->values()->all(),
            ],
        ]);
    }

    /**
     * Checks one section. Nothing is saved.
     */
    public function check(NominationDetailsSectionRequest $request): Response
    {
        return response()->noContent();
    }

    /**
     * Confirms Stage 2, atomically and once.
     */
    public function store(NominationDetailsRequest $request): JsonResponse
    {
        [$outcome, $journey] = $this->details->confirm($request->user(), $request->validated(), (string) $request->input('journey_key'));

        return match ($outcome) {
            NominationDetails::CONFIRMED => response()->json([
                'message' => 'حُفظت بيانات هذه المرحلة، وأصبحت متاحة للعرض فقط.',
                'data' => new NominationDetailsResource($journey),
            ], 201),
            NominationDetails::ALREADY_CONFIRMED => response()->json([
                'code' => $outcome,
                'message' => 'بيانات هذه المرحلة محفوظة مسبقاً ومتاحة للعرض فقط، ولم يُغيَّر فيها شيء.',
                'data' => new NominationDetailsResource($journey),
            ], 409),
            NominationDetails::OPTION_UNAVAILABLE => response()->json([
                'code' => $outcome,
                'message' => 'لم يعد المحور أو الفئة المختارة متاحاً، ولم يُحفظ شيء. أعد تحميل الصفحة لاختيار محور متاح.',
            ], 409),
            NominationDetails::UNAVAILABLE => response()->json([
                'code' => $outcome,
                'message' => 'هذه المرحلة غير متاحة حالياً، ولم يُحفظ شيء. ما أدخلته ما زال في الصفحة.',
            ], 409),
            NominationDetails::JOURNEY_CHANGED => response()->json([
                'code' => $outcome,
                'message' => 'تغيّرت إعدادات هذه المرحلة منذ فتحت النموذج، ولم يُحفظ شيء. أعد تحميل الصفحة.',
            ], 409),
            default => response()->json([
                'code' => NominationDetails::BASIC_INFORMATION_REQUIRED,
                'message' => 'أكمل البيانات الأساسية (المرحلة 1) وأكّد حفظها أولاً.',
            ], 409),
        };
    }
}
