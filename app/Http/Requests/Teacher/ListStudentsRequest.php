<?php

namespace App\Http\Requests\Teacher;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListStudentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isTeacher() ?? false;
    }

    public function rules(): array
    {
        return [
            'course_id'     => ['nullable', 'integer', 'exists:courses,id'],
            'search'        => ['nullable', 'string', 'max:255'],
            'status'        => ['nullable', Rule::in(['active', 'inactive'])],
            'view'          => ['nullable', Rule::in(['enrollments', 'students'])],
            'enrolled_from' => ['nullable', 'date'],
            'enrolled_to'   => ['nullable', 'date', 'after_or_equal:enrolled_from'],
            'per_page'      => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
