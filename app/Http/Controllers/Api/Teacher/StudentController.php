<?php

namespace App\Http\Controllers\Api\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Requests\Teacher\ListStudentsRequest;
use App\Http\Requests\Teacher\RemoveEnrollmentRequest;
use App\Http\Requests\Teacher\UpdateEnrollmentRequest;
use App\Http\Resources\Teacher\EnrollmentResource;
use App\Http\Resources\Teacher\TeacherStudentDetailResource;
use App\Services\Teacher\TeacherStudentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StudentController extends Controller
{
    public function __construct(
        private readonly TeacherStudentService $studentService
    ) {}

    /**
     * GET /teacher/students
     * Liste des inscriptions ou des étudiants uniques (view=students).
     */
    public function index(ListStudentsRequest $request): JsonResponse
    {
        $teacherId = Auth::id();
        $filters = $request->validated();

        if (($filters['view'] ?? 'enrollments') === 'students') {
            $students = $this->studentService->listUniqueStudents($teacherId, $filters);

            return response()->json([
                'data' => $students->items(),
                'meta' => $this->paginationMeta($students),
            ]);
        }

        $enrollments = $this->studentService->listEnrollments($teacherId, $filters);

        return response()->json([
            'data' => EnrollmentResource::collection($enrollments->items()),
            'meta' => $this->paginationMeta($enrollments),
        ]);
    }

    /**
     * GET /teacher/students/{student}
     * Profil étudiant + inscriptions chez ce teacher.
     */
    public function show(int $student): JsonResponse
    {
        $detail = $this->studentService->getStudentDetail(Auth::id(), $student);

        return response()->json(new TeacherStudentDetailResource($detail));
    }

    /**
     * PATCH /teacher/enrollments/{enrollment}
     * Notes internes sur une inscription (ex: suivi pédagogique).
     */
    public function updateEnrollment(UpdateEnrollmentRequest $request, int $enrollment): JsonResponse
    {
        $updated = $this->studentService->updateEnrollment(
            Auth::id(),
            $enrollment,
            $request->validated()
        );

        return response()->json([
            'message'    => 'Inscription mise à jour avec succès.',
            'enrollment' => new EnrollmentResource($updated),
        ]);
    }

    /**
     * DELETE /teacher/students/{student}
     * Retire l'étudiant d'un cours (course_id requis).
     */
    public function destroy(RemoveEnrollmentRequest $request, int $student): JsonResponse
    {
        $this->studentService->removeEnrollment(
            Auth::id(),
            $student,
            (int) $request->validated('course_id')
        );

        return response()->json([
            'message' => 'Inscription supprimée avec succès.',
        ]);
    }

    /**
     * GET /teacher/courses/{course}/students
     * Étudiants inscrits à un cours du teacher.
     */
    public function byCourse(int $course, Request $request): JsonResponse
    {
        $request->validate([
            'search'   => ['nullable', 'string', 'max:255'],
            'status'   => ['nullable', 'in:active,inactive'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $result = $this->studentService->listByCourse(Auth::id(), $course, $request->all());

        return response()->json([
            'course' => $result['course']->only(['id', 'title', 'slug', 'thumbnail', 'status']),
            'students' => [
                'data' => EnrollmentResource::collection($result['students']->items()),
                'meta' => $this->paginationMeta($result['students']),
            ],
        ]);
    }

    private function paginationMeta($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
            'per_page'     => $paginator->perPage(),
            'total'        => $paginator->total(),
        ];
    }
}
