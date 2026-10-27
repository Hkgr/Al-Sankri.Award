<?php

namespace App\Http\Resources;

use App\Models\AccountPolicyVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Which version of a policy is published: enough to link to it and to send
 * back its id when accepting it.
 *
 * @mixin AccountPolicyVersion
 */
class PolicyVersionSummary extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'code' => $this->code,
            'version_number' => $this->version_number,
            'title' => $this->title_ar,
            'published_at' => $this->published_at?->toIso8601ZuluString(),
        ];
    }
}
