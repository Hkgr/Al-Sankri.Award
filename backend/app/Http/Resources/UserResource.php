<?php

namespace App\Http\Resources;

use App\Auth\GoogleAccounts;
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
        ];
    }
}
