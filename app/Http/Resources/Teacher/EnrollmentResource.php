<?php

namespace App\Http\Resources\Teacher;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EnrollmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'enrolled_at'   => $this->enrolled_at?->toIso8601String(),
            'teacher_notes' => $this->teacher_notes,
            'student'       => $this->whenLoaded('student', fn () => [
                'id'              => $this->student->id,
                'name'            => $this->student->name,
                'email'           => $this->student->email,
                'profile_picture' => $this->student->profile_picture,
                'country'         => $this->student->country,
                'headline'        => $this->student->headline,
                'is_active'       => $this->student->is_active,
            ]),
            'course' => $this->whenLoaded('course', fn () => [
                'id'        => $this->course->id,
                'title'     => $this->course->title,
                'slug'      => $this->course->slug,
                'thumbnail' => $this->course->thumbnail,
                'level'     => $this->course->level ?? null,
                'price'     => isset($this->course->price) ? (float) $this->course->price : null,
                'is_free'   => $this->course->is_free ?? null,
                'status'    => $this->course->status ?? null,
            ]),
            'purchase' => $this->whenLoaded('purchase', fn () => $this->purchase ? [
                'id'            => $this->purchase->id,
                'amount_total'  => (float) $this->purchase->amount_total,
                'teacher_amount'=> (float) $this->purchase->teacher_amount,
                'currency'      => $this->purchase->currency,
                'status'        => $this->purchase->status,
                'purchased_at'  => $this->purchase->purchased_at?->toIso8601String(),
            ] : null),
        ];
    }
}
