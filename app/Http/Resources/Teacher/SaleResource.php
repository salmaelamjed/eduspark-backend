<?php

declare(strict_types=1);

namespace App\Http\Resources\Teacher;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'amount_total'   => (float) $this->amount_total,
            'teacher_amount' => (float) $this->teacher_amount,
            'currency'       => $this->currency,
            'purchased_at'   => $this->purchased_at?->toIso8601String(),
            'student'        => $this->whenLoaded('student', fn () => [
                'id'    => $this->student->id,
                'name'  => $this->student->name,
                'email' => $this->student->email,
            ]),
            'course' => $this->whenLoaded('course', fn () => [
                'id'    => $this->course->id,
                'title' => $this->course->title,
                'slug'  => $this->course->slug,
            ]),
        ];
    }
}
