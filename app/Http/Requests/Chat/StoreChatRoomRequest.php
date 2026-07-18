<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

class StoreChatRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'student';
    }

    public function rules(): array
    {
        return [
            'course_id' => ['required', 'integer', 'exists:courses,id'],
            'lesson_id' => ['nullable', 'integer', 'exists:lessons,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'course_id.exists' => "Ce cours n'existe pas.",
            'lesson_id.exists' => "Cette leçon n'existe pas.",
        ];
    }
}