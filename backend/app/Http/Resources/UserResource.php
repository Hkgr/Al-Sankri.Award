<?php

namespace App\Http\Resources;

use App\Admin\AdminAccess;
use App\Auth\CandidateRole;
use App\Auth\GoogleAccounts;
use App\Candidates\AccountContext;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The only account fields the browser receives. Internal columns (status
 * reasons, phone, timestamps, identifiers) are deliberately left out.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'email_verified' => $this->hasVerifiedEmail(),
            'has_password' => $this->hasPassword(),
            'google_linked' => app(GoogleAccounts::class)->isLinked($this->resource),
            'is_candidate' => app(CandidateRole::class)->isHeldBy($this->resource),
            // Codes only; current role grants decide them on the server.
            'admin_permissions' => app(AdminAccess::class)->permissionsOf($this->resource),
            'is_judge' => app(AdminAccess::class)->isJudge((int) $this->resource->getKey()),
            // Opaque; candidate pages send it back so the server can refuse
            // requests made under a stale account snapshot.
            'account_context' => AccountContext::for($this->resource),
        ];
    }
}
