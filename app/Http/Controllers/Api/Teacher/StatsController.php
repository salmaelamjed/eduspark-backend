<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Requests\Teacher\CourseStatsRequest;
use App\Http\Requests\Teacher\DashboardStatsRequest;
use App\Http\Resources\Teacher\EnrollmentResource;
use App\Http\Resources\Teacher\SaleResource;
use App\Services\Teacher\TeacherStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class StatsController extends Controller
{
    // Désactiver le cache en mettant à 0 ou en commentant
    // private const DASHBOARD_CACHE_TTL = 300;
    // private const COURSE_STATS_CACHE_TTL = 300;

    public function __construct(
        private readonly TeacherStatsService $statsService
    ) {}

    /**
     * GET /teacher/stats/dashboard
     * Vue d'ensemble : revenus, inscriptions, tendances, top cours.
     */
    public function dashboard(DashboardStatsRequest $request): JsonResponse
    {
        $teacherId = Auth::id();
        $period = $request->period();

        $data = $this->statsService->dashboard($teacherId, $period);

        return response()->json([
            'period'               => $period,
            'generated_at'         => now()->toIso8601String(),
            'overview'             => $data['overview'],
            'best_selling_courses' => $data['best_selling_courses'],
            'most_popular_courses' => $data['most_popular_courses'],
            'enrollment_trend'     => $data['enrollment_trend'],
            'revenue_trend'        => $data['revenue_trend'],
            'recent_enrollments'   => $data['recent_enrollments'],
            'recent_sales'         => $data['recent_sales'],
            'top_students'         => $data['top_students'],
        ]);
    }

    /**
     * GET /teacher/stats/courses/{course}
     * Statistiques détaillées d'un cours.
     */
    public function courseStats(int $course, CourseStatsRequest $request): JsonResponse
    {
        $teacherId = Auth::id();

        // Supprimer le cache existant
        Cache::forget("teacher:{$teacherId}:course-stats:{$course}");

        // Récupérer directement sans cache
        $data = $this->statsService->courseStats($teacherId, $course);

        return response()->json([
            'generated_at'        => now()->toIso8601String(),
            'course'              => $data['course']->only([
                'id', 'title', 'slug', 'thumbnail', 'price', 'is_free', 'status', 'level',
            ]),
            'stats'               => $data['stats'],
            'enrollment_trend'    => $data['enrollment_trend'],
            'recent_enrollments'  => $data['recent_enrollments'],
        ]);
    }
}
