<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseEnrollment;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CourseAccessController extends Controller
{
    /**
     * Détermine si l'utilisateur connecté a le droit d'accéder au contenu
     * complet du cours (gratuit, propriétaire/teacher, ou déjà inscrit).
     * Utilisé à la fois par le bouton d'action (front) et comme garde-fou
     * réel côté serveur pour le contenu (CourseController::show).
     */
    public static function resolve(Course $course, ?\App\Models\User $user): array
    {
        if ($course->is_free) {
            return ['has_access' => true, 'reason' => 'free'];
        }

        if ($user && $user->id === $course->teacher_id) {
            return ['has_access' => true, 'reason' => 'owner'];
        }

        if ($user) {
            $enrolled = CourseEnrollment::where('course_id', $course->id)
                ->where('student_id', $user->id)
                ->exists();

            if ($enrolled) {
                return ['has_access' => true, 'reason' => 'enrolled'];
            }
        }

        return ['has_access' => false, 'reason' => 'not_purchased'];
    }

    public function check(Request $request, Course $course): JsonResponse
    {
        $access = self::resolve($course, $request->user());

        return response()->json([
            'course_id' => $course->id,
            'has_access' => $access['has_access'],
            'reason' => $access['reason'],
            'is_free' => $course->is_free,
        ]);
    }
}   
