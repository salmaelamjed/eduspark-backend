<?php

namespace App\Http\Resources\Teacher;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeacherStudentDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'student' => [
                'id'              => $this->resource['student']->id,
                'name'            => $this->resource['student']->name,
                'email'           => $this->resource['student']->email,
                'profile_picture' => $this->resource['student']->profile_picture,
                'country'         => $this->resource['student']->country,
                'headline'        => $this->resource['student']->headline,
                'bio'             => $this->resource['student']->bio,
                'expertise_level' => $this->resource['student']->expertise_level,
                'is_active'       => $this->resource['student']->is_active,
                'created_at'      => $this->resource['student']->created_at?->toIso8601String(),
            ],
            'enrollments' => EnrollmentResource::collection($this->resource['enrollments']),
            'stats'       => $this->resource['stats'],
        ];
    }
}
