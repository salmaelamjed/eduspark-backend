<?php

namespace App\Http\Resources\User;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class UserProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->when(
                $request->user() && $request->user()->id === $this->id,
                $this->email
            ),
            'role' => $this->role,
            'is_active' => $this->is_active,
            'country' => $this->country,
            'headline' => $this->headline,
            'bio' => $this->bio,
            'expertise_level' => $this->expertise_level,
            'date_of_birth' => $this->when(
                $request->user() && $request->user()->id === $this->id,
                optional($this->date_of_birth)?->format('Y-m-d')
            ),
            'social_links' => $this->social_links,
            'profile_picture_url' => $this->profile_picture
                ? Storage::disk('public')->url($this->profile_picture)
                : null,
            'email_verified' => (bool) $this->email_verified_at,
            'member_since' => $this->created_at?->format('Y-m-d'),
            'courses_count' => $this->when(
                $this->role === 'teacher',
                $this->courses_count ?? 0
            ),
        ];
    }
}
