<?php

declare(strict_types=1);

namespace App\Http\Requests\Teacher;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class CourseStatsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // L'appartenance du cours au teacher est vérifiée dans le service
        // (Course::where('teacher_id', ...)->firstOrFail()), qui lève un
        // 404 — jamais un 403 — pour ne pas confirmer l'existence du cours
        // à un enseignant qui n'en est pas propriétaire.
        return Auth::check() && Auth::user()->role === 'teacher';
    }

    public function rules(): array
    {
        return [];
    }
}
