<?php

namespace App\Services\Teacher;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CoursePurchase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TeacherStatsService
{
    public function dashboard(int $teacherId, int $periodDays = 30): array
    {
        $dateFrom = now()->subDays($periodDays)->startOfDay();
        $previousFrom = now()->subDays($periodDays * 2)->startOfDay();
        $previousTo = $dateFrom->copy()->subSecond();

        $overview = $this->buildOverview($teacherId, $dateFrom, $previousFrom, $previousTo, $periodDays);

        return [
            'overview'              => $overview,
            'best_selling_courses'  => $this->bestSellingCourses($teacherId),
            'most_popular_courses'  => $this->mostPopularCourses($teacherId),
            'enrollment_trend'      => $this->enrollmentTrend($teacherId, $dateFrom),
            'revenue_trend'         => $this->revenueTrend($teacherId, $dateFrom),
            'recent_enrollments'    => $this->recentEnrollments($teacherId),
            'recent_sales'          => $this->recentSales($teacherId),
            'top_students'          => $this->topStudentsBySpend($teacherId),
        ];
    }

    public function courseStats(int $teacherId, int $courseId): array
    {
        $course = Course::query()
            ->where('id', $courseId)
            ->where('teacher_id', $teacherId)
            ->firstOrFail(['id', 'title', 'slug', 'thumbnail', 'price', 'is_free', 'status', 'level']);

        $completedPurchases = $course->purchases()->where('status', 'completed');

        return [
            'course' => $course,
            'stats'  => [
                'enrollments_count'   => $course->enrollments()->count(),
                'unique_students'     => $course->enrollments()->distinct('student_id')->count('student_id'),
                'sales_count'         => (clone $completedPurchases)->count(),
                'total_earned'        => (float) (clone $completedPurchases)->sum('teacher_amount'),
                'gross_revenue'       => (float) (clone $completedPurchases)->sum('amount_total'),
                'average_sale_amount' => (float) ((clone $completedPurchases)->avg('amount_total') ?? 0),
                'free_enrollments'    => $course->enrollments()->whereNull('purchase_id')->count(),
                'paid_enrollments'    => $course->enrollments()->whereNotNull('purchase_id')->count(),
            ],
            'enrollment_trend' => $this->enrollmentTrendForCourse($course->id, now()->subDays(30)->startOfDay()),
            'recent_enrollments' => $course->enrollments()
                ->with(['student:id,name,email,profile_picture'])
                ->orderByDesc('enrolled_at')
                ->limit(10)
                ->get(),
        ];
    }

    private function buildOverview(
        int $teacherId,
        $dateFrom,
        $previousFrom,
        $previousTo,
        int $periodDays
    ): array {
        $enrollmentQuery = CourseEnrollment::forTeacher($teacherId);
        $purchaseQuery = CoursePurchase::where('teacher_id', $teacherId)->where('status', 'completed');

        $revenue = (clone $purchaseQuery)->selectRaw('
            COALESCE(SUM(teacher_amount), 0) as total_earnings,
            COALESCE(SUM(commission_amount), 0) as total_commission,
            COALESCE(SUM(amount_total), 0) as gross_revenue,
            COUNT(*) as total_sales
        ')->first();

        $periodEnrollments = (clone $enrollmentQuery)->where('enrolled_at', '>=', $dateFrom)->count();
        $previousEnrollments = (clone $enrollmentQuery)
            ->whereBetween('enrolled_at', [$previousFrom, $previousTo])
            ->count();

        $periodEarnings = (float) (clone $purchaseQuery)
            ->where('purchased_at', '>=', $dateFrom)
            ->sum('teacher_amount');

        $previousEarnings = (float) (clone $purchaseQuery)
            ->whereBetween('purchased_at', [$previousFrom, $previousTo])
            ->sum('teacher_amount');

        return [
            'total_students'           => (clone $enrollmentQuery)->distinct('student_id')->count('student_id'),
            'total_enrollments'        => (clone $enrollmentQuery)->count(),
            'total_courses'            => Course::where('teacher_id', $teacherId)->count(),
            'published_courses'        => Course::where('teacher_id', $teacherId)->where('status', 'published')->count(),
            'total_earnings'           => (float) ($revenue->total_earnings ?? 0),
            'total_commission'         => (float) ($revenue->total_commission ?? 0),
            'gross_revenue'            => (float) ($revenue->gross_revenue ?? 0),
            'total_sales'              => (int) ($revenue->total_sales ?? 0),
            'period_days'              => $periodDays,
            'period_enrollments'       => $periodEnrollments,
            'period_earnings'          => $periodEarnings,
            'enrollments_growth_pct'   => $this->growthPercent($periodEnrollments, $previousEnrollments),
            'earnings_growth_pct'      => $this->growthPercent($periodEarnings, $previousEarnings),
        ];
    }

    private function bestSellingCourses(int $teacherId, int $limit = 5): Collection
    {
        return Course::where('teacher_id', $teacherId)
            ->withCount(['purchases as sales_count' => fn ($q) => $q->where('status', 'completed')])
            ->withSum(['purchases as total_earned' => fn ($q) => $q->where('status', 'completed')], 'teacher_amount')
            ->orderByDesc('sales_count')
            ->limit($limit)
            ->get(['id', 'title', 'slug', 'thumbnail', 'price', 'is_free', 'status']);
    }

    private function mostPopularCourses(int $teacherId, int $limit = 5): Collection
    {
        return Course::where('teacher_id', $teacherId)
            ->withCount('enrollments')
            ->orderByDesc('enrollments_count')
            ->limit($limit)
            ->get(['id', 'title', 'slug', 'thumbnail', 'status']);
    }

    private function enrollmentTrend(int $teacherId, $dateFrom): Collection
    {
        return CourseEnrollment::forTeacher($teacherId)
            ->where('enrolled_at', '>=', $dateFrom)
            ->select(DB::raw('DATE(enrolled_at) as date'), DB::raw('COUNT(*) as count'))
            ->groupBy('date')
            ->orderBy('date')
            ->get();
    }

    private function enrollmentTrendForCourse(int $courseId, $dateFrom): Collection
    {
        return CourseEnrollment::where('course_id', $courseId)
            ->where('enrolled_at', '>=', $dateFrom)
            ->select(DB::raw('DATE(enrolled_at) as date'), DB::raw('COUNT(*) as count'))
            ->groupBy('date')
            ->orderBy('date')
            ->get();
    }

    private function revenueTrend(int $teacherId, $dateFrom): Collection
    {
        return CoursePurchase::where('teacher_id', $teacherId)
            ->where('status', 'completed')
            ->where('purchased_at', '>=', $dateFrom)
            ->select(DB::raw('DATE(purchased_at) as date'), DB::raw('SUM(teacher_amount) as amount'))
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($row) => [
                'date'   => $row->date,
                'amount' => (float) $row->amount,
            ]);
    }

    private function recentEnrollments(int $teacherId, int $limit = 8): Collection
    {
        return CourseEnrollment::forTeacher($teacherId)
            ->with([
                'student:id,name,email,profile_picture',
                'course:id,title,slug',
            ])
            ->orderByDesc('enrolled_at')
            ->limit($limit)
            ->get()
             ->map(fn (CourseEnrollment $enrollment) => [
            'id'           => $enrollment->id,
            'student_name' => $enrollment->student->name ?? null,
            'course_title' => $enrollment->course->title ?? null,
            'enrolled_at'  => $enrollment->enrolled_at?->toIso8601String(),
            'status'       => $enrollment->purchase_id ? 'paid' : 'free',
           ]);
    }

    private function recentSales(int $teacherId, int $limit = 8): Collection
    {
        return CoursePurchase::where('teacher_id', $teacherId)
            ->where('status', 'completed')
            ->with([
                'student:id,name,email',
                'course:id,title,slug',
            ])
            ->orderByDesc('purchased_at')
            ->limit($limit)
            ->get(['id', 'course_id', 'student_id', 'amount_total', 'teacher_amount', 'currency', 'purchased_at'])
             ->map(fn (CoursePurchase $purchase) => [
            'id'           => $purchase->id,
            'course_title' => $purchase->course->title ?? null,
            'student_name' => $purchase->student->name ?? null,
            'amount'       => (float) $purchase->amount_total,
            'currency'     => $purchase->currency,
            'purchased_at' => $purchase->purchased_at?->toIso8601String(),
        ]);
    }

    private function topStudentsBySpend(int $teacherId, int $limit = 5): Collection
    {
        return CoursePurchase::query()
            ->where('teacher_id', $teacherId)
            ->where('status', 'completed')
            ->select('student_id', DB::raw('SUM(amount_total) as total_spent'), DB::raw('COUNT(*) as purchases_count'))
            ->groupBy('student_id')
            ->orderByDesc('total_spent')
            ->limit($limit)
            ->with('student:id,name,email,profile_picture')
            ->get()
            ->map(fn ($row) => [
                'student'          => $row->student,
                'total_spent'      => (float) $row->total_spent,
                'purchases_count'  => (int) $row->purchases_count,
            ]);
    }

    private function growthPercent(float|int $current, float|int $previous): ?float
    {
        if ($previous == 0) {
            return $current > 0 ? 100.0 : null;
        }

        return round((($current - $previous) / $previous) * 100, 2);
    }
}
