<?php

namespace App\Http\Controllers;

use App\Models\AccountPolicyVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountPolicyController extends Controller
{
    /**
     * URL slugs of the public policy pages.
     *
     * @var array<string, string>
     */
    public const SLUGS = [
        'privacy-policy' => AccountPolicyVersion::PRIVACY_POLICY,
        'terms-of-use' => AccountPolicyVersion::TERMS_OF_USE,
    ];

    /**
     * The published text of a policy, or an earlier published version by
     * number (?version=). Drafts are never shown. Without a published
     * version the answer is 404 with code policy_not_configured.
     */
    public function __invoke(Request $request, string $policy): JsonResponse
    {
        $code = self::SLUGS[$policy];
        $query = AccountPolicyVersion::query()->where('code', $code);
        $version = $request->query('version');

        if (is_string($version) && ctype_digit($version)) {
            $query->where('version_number', (int) $version)->where('status', '!=', AccountPolicyVersion::STATUS_DRAFT);
        } else {
            $query->where('status', AccountPolicyVersion::STATUS_PUBLISHED);
        }

        $found = $query->first();

        if ($found === null) {
            return response()->json([
                'message' => AccountPolicyVersion::NAMES[$code].' غير منشورة بعد.',
                'code' => 'policy_not_configured',
            ], 404);
        }

        return response()->json([
            'data' => [
                'code' => $found->code,
                'title' => $found->title_ar,
                'body' => $found->body_ar,
                'version_number' => $found->version_number,
                'status' => $found->status,
                'published_at' => $found->published_at?->toIso8601ZuluString(),
            ],
        ]);
    }
}
