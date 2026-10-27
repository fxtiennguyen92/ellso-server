<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'avatar' => $this->avatar,
            'role' => $this->role,
            'referral_code' => $this->referral_code,
            'referrer' => $this->whenLoaded('referrer', fn () => $this->referrer ? [
                'name' => $this->referrer->name,
                'referral_code' => $this->referrer->referral_code,
            ] : null),
            // Gói thành viên (1 năm): ends_at = ngày hết hạn xa nhất của các gói còn hạn
            'membership' => [
                'active' => ($endsAt = $this->membershipEndsAt()) !== null,
                'ends_at' => $endsAt?->toIso8601String(),
            ],
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
