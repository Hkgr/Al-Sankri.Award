<?php

namespace App\Http\Controllers\Candidate;

use App\Candidates\AccountContext;
use App\Candidates\BasicProfiles;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Requests\Candidate\BasicProfileRequest;
use App\Http\Requests\Candidate\BasicProfileSectionRequest;
use App\Http\Resources\BasicProfileResource;
use App\Http\Resources\PolicyVersionSummary;
use App\Models\AccountPolicyVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Stage 1: the signed-in candidate's own basic information. There is no
 * identifier in any URL: every action concerns the requesting account only.
 * Once confirmed, the information is read-only; there is no update, replace
 * or delete endpoint, and a second confirmation is refused (409).
 */
class BasicProfileController extends Controller
{
    public function __construct(private readonly BasicProfiles $profiles) {}

    /**
     * The confirmed information, or null with what confirmation needs, and
     * the account context the form must send back with its requests.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = $this->profiles->profileOf($user);

        return response()->json([
            'data' => $profile === null ? null : new BasicProfileResource($profile),
            'requirements' => $this->requirements($user->hasVerifiedEmail()),
            'account_context' => AccountContext::for($user),
        ]);
    }

    /**
     * Checks one internal section of the form. Nothing is saved.
     */
    public function check(BasicProfileSectionRequest $request): Response
    {
        return response()->noContent();
    }

    /**
     * Confirms the basic information, atomically and once.
     */
    public function store(BasicProfileRequest $request): JsonResponse
    {
        [$outcome, $profile] = $this->profiles->finalize($request->user(), $request->validated());

        return match ($outcome) {
            BasicProfiles::COMPLETED => response()->json([
                'message' => 'حُفظت بياناتك الأساسية، وأصبحت متاحة للعرض فقط.',
                'data' => new BasicProfileResource($profile),
            ], 201),
            BasicProfiles::ALREADY_COMPLETED => response()->json([
                'message' => 'بياناتك الأساسية محفوظة مسبقاً ومتاحة للعرض فقط، ولم يُغيَّر فيها شيء.',
                'code' => $outcome,
                'data' => new BasicProfileResource($profile),
            ], 409),
            BasicProfiles::EMAIL_UNVERIFIED => response()->json([
                'message' => 'أكّد بريدك الإلكتروني أولاً، ثم أعد تأكيد الحفظ. ما أدخلته ما زال في النموذج.',
                'code' => $outcome,
            ], 403),
            BasicProfiles::POLICIES_NOT_CONFIGURED => response()->json([
                'message' => 'لا يمكن حفظ البيانات الأساسية حالياً: لم تُنشر سياسة الخصوصية وشروط الاستخدام المعتمدة بعد.',
                'code' => $outcome,
                'requirements' => $this->requirements(true),
            ], 409),
            BasicProfiles::POLICY_CHANGED => response()->json([
                'message' => 'نُشر إصدار جديد من سياسة الخصوصية أو شروط الاستخدام. اقرأ الإصدار الحالي ووافق عليه، ثم أعد تأكيد الحفظ.',
                'code' => $outcome,
                'requirements' => $this->requirements(true),
            ], 409),
            default => response()->json(['message' => EnsureAccountIsActive::MESSAGE, 'code' => 'account_disabled'], 403),
        };
    }

    /**
     * @return array{email_verified: bool, policies: array<string, PolicyVersionSummary|null>, finalization_available: bool}
     */
    private function requirements(bool $emailVerified): array
    {
        $policies = $this->profiles->requiredPolicies();

        return [
            'email_verified' => $emailVerified,
            'policies' => array_map(
                fn (?AccountPolicyVersion $policy): ?PolicyVersionSummary => $policy === null ? null : new PolicyVersionSummary($policy),
                $policies,
            ),
            'finalization_available' => ! in_array(null, $policies, true),
        ];
    }
}
