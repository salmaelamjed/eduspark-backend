<?php

namespace App\Services\Teacher;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TeacherStudentService
{
    /**
     * Liste paginée des inscriptions (une ligne par enrollment).
     */
    private const COURSE_ENROLLMENT_COLUMNS = 'course:id,title,slug,thumbnail,level,price,is_free,status';
    private const STUDENT_ENROLLMENT_COLUMNS = 'student:id,name,email,profile_picture,country,headline,is_active';

    public function listEnrollments(int $teacherId, array $filters): LengthAwarePaginator
    {
        return $this->enrollmentBaseQuery($teacherId, $filters)
            ->with([self::STUDENT_ENROLLMENT_COLUMNS, self::COURSE_ENROLLMENT_COLUMNS])
            ->orderByDesc('course_enrollments.enrolled_at')
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Liste paginée des étudiants uniques avec agrégats.
     */
    public function listUniqueStudents(int $teacherId, array $filters): LengthAwarePaginator
    {
        $query = User::query()
            ->select([
                'users.id',
                'users.name',
                'users.email',
                'users.profile_picture',
                'users.country',
                'users.headline',
                'users.is_active',
                DB::raw('COUNT(course_enrollments.id) as enrollments_count'),
                DB::raw('MAX(course_enrollments.enrolled_at) as last_enrolled_at'),
                DB::raw('MIN(course_enrollments.enrolled_at) as first_enrolled_at'),
            ])
            ->join('course_enrollments', 'course_enrollments.student_id', '=', 'users.id')
            ->join('courses', 'courses.id', '=', 'course_enrollments.course_id')
            ->where('courses.teacher_id', $teacherId)
            ->where('users.role', 'student')
            ->groupBy(
                'users.id',
                'users.name',
                'users.email',
                'users.profile_picture',
                'users.country',
                'users.headline',
                'users.is_active'
            );

        $this->applyStudentFilters($query, $filters, 'users');

        return $query
            ->orderByDesc('last_enrolled_at')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function getStudentDetail(int $teacherId, int $studentId): array
    {
        $student = User::query()
            ->where('id', $studentId)
            ->where('role', 'student')
            ->firstOrFail();

        $enrollments = CourseEnrollment::query()
            ->forTeacher($teacherId)
            ->with([
                self::COURSE_ENROLLMENT_COLUMNS,
                'purchase:id,amount_total,teacher_amount,currency,status,purchased_at',
            ])
            ->where('student_id', $studentId)
            ->orderByDesc('enrolled_at')
            ->get();

        if ($enrollments->isEmpty()) {
            abort(403, 'Cet étudiant n\'est inscrit à aucun de vos cours.');
        }

        return [
            'student'     => $student,
            'enrollments' => $enrollments,
            'stats'       => [
                'total_courses'    => $enrollments->count(),
                'total_spent'      => $enrollments->sum(fn ($e) => (float) ($e->purchase?->amount_total ?? 0)),
                'first_enrollment' => $enrollments->min('enrolled_at'),
                'last_enrollment'  => $enrollments->max('enrolled_at'),
            ],
        ];
    }

    public function updateEnrollment(int $teacherId, int $enrollmentId, array $data): CourseEnrollment
    {
        $enrollment = CourseEnrollment::query()
            ->forTeacher($teacherId)
            ->findOrFail($enrollmentId);

        $enrollment->update($data);

        return $enrollment->load(['student:id,name,email', 'course:id,title,slug']);
    }

    public function removeEnrollment(int $teacherId, int $studentId, int $courseId): void
    {
        $enrollment = CourseEnrollment::query()
            ->forTeacher($teacherId)
            ->where('student_id', $studentId)
            ->where('course_id', $courseId)
            ->firstOrFail();

        $enrollment->delete();
    }

    public function listByCourse(int $teacherId, int $courseId, array $filters): array
    {
        $course = Course::query()
            ->where('id', $courseId)
            ->where('teacher_id', $teacherId)
            ->firstOrFail(['id', 'title', 'slug', 'thumbnail', 'status']);

        $students = CourseEnrollment::query()
            ->where('course_id', $course->id)
            ->with(['student:id,name,email,profile_picture,country,is_active,headline'])
            ->when($filters['search'] ?? null, function (Builder $q, string $search) {
                $like = '%' . $search . '%';
                $q->whereHas('student', fn (Builder $sub) => $sub
                    ->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like));
            })
            ->when(($filters['status'] ?? null) === 'active', fn (Builder $q) => $q->whereHas(
                'student',
                fn (Builder $sub) => $sub->where('is_active', true)
            ))
            ->when(($filters['status'] ?? null) === 'inactive', fn (Builder $q) => $q->whereHas(
                'student',
                fn (Builder $sub) => $sub->where('is_active', false)
            ))
            ->orderByDesc('enrolled_at')
            ->paginate($filters['per_page'] ?? 20);

        return compact('course', 'students');
    }

    private function enrollmentBaseQuery(int $teacherId, array $filters): Builder
    {
        $query = CourseEnrollment::query()
            ->forTeacher($teacherId)
            ->when($filters['course_id'] ?? null, fn (Builder $q, $courseId) => $q->where('course_id', $courseId))
            ->when($filters['search'] ?? null, function (Builder $q, string $search) {
                $like = '%' . $search . '%';
                $q->whereHas('student', fn (Builder $sub) => $sub
                    ->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like));
            })
            ->when(($filters['status'] ?? null) === 'active', fn (Builder $q) => $q->whereHas(
                'student',
                fn (Builder $sub) => $sub->where('is_active', true)
            ))
            ->when(($filters['status'] ?? null) === 'inactive', fn (Builder $q) => $q->whereHas(
                'student',
                fn (Builder $sub) => $sub->where('is_active', false)
            ))
            ->when($filters['enrolled_from'] ?? null, fn (Builder $q, $date) => $q->whereDate('enrolled_at', '>=', $date))
            ->when($filters['enrolled_to'] ?? null, fn (Builder $q, $date) => $q->whereDate('enrolled_at', '<=', $date));

        return $query;
    }

    private function applyStudentFilters(Builder $query, array $filters, string $table = 'users'): void
    {
        if ($filters['course_id'] ?? null) {
            $query->where('course_enrollments.course_id', $filters['course_id']);
        }

        if ($filters['search'] ?? null) {
            $like = '%' . $filters['search'] . '%';
            $query->where(function (Builder $q) use ($like, $table) {
                $q->where("{$table}.name", 'like', $like)
                    ->orWhere("{$table}.email", 'like', $like);
            });
        }

        if (($filters['status'] ?? null) === 'active') {
            $query->where("{$table}.is_active", true);
        }

        if (($filters['status'] ?? null) === 'inactive') {
            $query->where("{$table}.is_active", false);
        }
    }
}
