<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Commission;
use App\Models\Course;
use App\Models\CoursePurchase;
use App\Models\Domain;
use App\Models\TeacherRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * AdminController
 *
 * Centralise toutes les actions du back-office admin :
 *  - Statistiques globales du dashboard (+ tendances de croissance)
 *  - Gestion des utilisateurs (students / teachers / admins)
 *  - Gestion des demandes pour devenir teacher
 *  - Gestion des domaines (catégories de cours)
 *  - Gestion des cours (modération / statut)
 *  - Vue finance (purchases / commissions)
 *
 * Sécurité :
 *  - Le middleware 'role:admin' doit être appliqué sur toutes les routes
 *    (voir routes/api.php). On revérifie quand même ici en défense en profondeur.
 */
class AdminController extends Controller implements HasMiddleware
{
    /**
     * Taille de la fenêtre glissante utilisée pour comparer "période actuelle"
     * vs "période précédente" dans les calculs de croissance (en jours).
     */
    private const TREND_WINDOW_DAYS = 30;

    public static function middleware(): array
    {
        return ['auth:sanctum', 'role:admin'];
    }

    /*
    |--------------------------------------------------------------------------
    | DASHBOARD - Statistiques globales
    |--------------------------------------------------------------------------
    */

    public function stats(): JsonResponse
    {
        $stats = [
            'users' => [
                'total'    => User::count(),
                'students' => User::where('role', 'student')->count(),
                'teachers' => User::where('role', 'teacher')->count(),
                'admins'   => User::where('role', 'admin')->count(),
                'active'   => User::where('is_active', true)->count(),
                'inactive' => User::where('is_active', false)->count(),
            ],
            'courses' => [
                'total'     => Course::count(),
                'published' => Course::where('status', 'published')->count(),
                'draft'     => Course::where('status', 'draft')->count(),
                'archived'  => Course::where('status', 'archived')->count(),
            ],
            'teacher_requests' => [
                'pending'  => TeacherRequest::where('status', 'pending')->count(),
                'approved' => TeacherRequest::where('status', 'approved')->count(),
                'rejected' => TeacherRequest::where('status', 'rejected')->count(),
            ],
            'finance' => [
                'total_revenue'        => (float) CoursePurchase::where('status', 'completed')->sum('amount_total'),
                'total_commission'     => (float) CoursePurchase::where('status', 'completed')->sum('commission_amount'),
                'total_teacher_payout' => (float) CoursePurchase::where('status', 'completed')->sum('teacher_amount'),
                'pending_commissions'  => (float) Commission::where('status', 'pending')->sum('amount'),
            ],
            'domains' => Domain::count(),
            'trends'  => $this->calculateTrends(),
        ];

        return $this->success($stats, 'Statistiques du dashboard récupérées avec succès.');
    }

    /**
 * Revenu mensuel des N derniers mois (par défaut 12), basé sur les achats complétés.
 * Utilisé pour le graphique "Revenue Growth" du dashboard admin.
 */
public function revenueByMonth(Request $request): JsonResponse
{
    $months = min(max($request->integer('months', 12), 1), 24);
    $start = Carbon::now()->startOfMonth()->subMonths($months - 1);

    $rows = CoursePurchase::where('status', 'completed')
        ->where('created_at', '>=', $start)
        ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as ym, SUM(amount_total) as total")
        ->groupBy('ym')
        ->orderBy('ym')
        ->pluck('total', 'ym');

    $data = [];
    $cursor = $start->copy();

    for ($i = 0; $i < $months; $i++) {
        $ym = $cursor->format('Y-m');
        $data[] = [
            'month'   => $cursor->translatedFormat('M'),
            'year'    => $cursor->year,
            'revenue' => (float) ($rows[$ym] ?? 0),
        ];
        $cursor->addMonth();
    }

    $total = array_sum(array_column($data, 'revenue'));

    // Cohérent avec calculateTrends() : fenêtre glissante 30j, pas le mois calendaire en cours
    $trend = $this->growthMetric(
        (float) CoursePurchase::where('status', 'completed')
            ->where('created_at', '>=', now()->subDays(30))
            ->sum('amount_total'),
        (float) CoursePurchase::where('status', 'completed')
            ->whereBetween('created_at', [now()->subDays(60), now()->subDays(30)])
            ->sum('amount_total')
    );

    return $this->success([
        'months'         => $data,
        'total'          => $total,
        'growth_percent' => $trend['growth_percent'],
    ], 'Revenu mensuel récupéré avec succès.');
}
    /**
     * Calcule les tendances de croissance (période actuelle vs période précédente,
     * fenêtres glissantes de TREND_WINDOW_DAYS jours) pour les métriques qui ont
     * un sens en delta temporel : revenue, nouveaux utilisateurs, nouveaux cours publiés.
     *
     * Important : on ne calcule PAS de tendance pour des totaux cumulatifs comme
     * "users.active" ou "domains" — leur variation réelle nécessiterait une table
     * de snapshots historiques (ex: un job planifié qui enregistre les compteurs
     * chaque jour). Sans ça, toute "tendance" sur un total cumulatif serait fausse
     * ou trompeuse ; mieux vaut ne rien afficher que d'afficher un chiffre inventé.
     */
    private function calculateTrends(): array
    {
        $now = Carbon::now();
        $periodStart = $now->copy()->subDays(self::TREND_WINDOW_DAYS);
        $previousPeriodStart = $now->copy()->subDays(self::TREND_WINDOW_DAYS * 2);

        // Revenue : somme des achats complétés, période courante vs précédente
        $currentRevenue = (float) CoursePurchase::where('status', 'completed')
            ->where('created_at', '>=', $periodStart)
            ->sum('amount_total');
        $previousRevenue = (float) CoursePurchase::where('status', 'completed')
            ->whereBetween('created_at', [$previousPeriodStart, $periodStart])
            ->sum('amount_total');

        // Nouveaux utilisateurs (tous rôles confondus) créés sur la période
        $currentNewUsers = User::where('created_at', '>=', $periodStart)->count();
        $previousNewUsers = User::whereBetween('created_at', [$previousPeriodStart, $periodStart])->count();

        // Nouveaux cours publiés sur la période
        $currentNewCourses = Course::where('status', 'published')
            ->where('created_at', '>=', $periodStart)
            ->count();
        $previousNewCourses = Course::where('status', 'published')
            ->whereBetween('created_at', [$previousPeriodStart, $periodStart])
            ->count();

        return [
            'revenue'     => $this->growthMetric($currentRevenue, $previousRevenue),
            'new_users'   => $this->growthMetric($currentNewUsers, $previousNewUsers),
            'new_courses' => $this->growthMetric($currentNewCourses, $previousNewCourses),
        ];
    }

    /**
 * Classement des cours les plus performants, par revenu ou par nombre
 * d'inscriptions (achats complétés). Utilisé pour le widget "Top cours"
 * du dashboard admin.
 */
public function topCourses(Request $request): JsonResponse
{
    $validator = Validator::make($request->all(), [
        'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        'by'    => ['nullable', Rule::in(['revenue', 'enrollments'])],
    ]);

    if ($validator->fails()) {
        return $this->validationError($validator);
    }

    $limit = $request->integer('limit', 5);
    $by = $request->string('by', 'revenue');

    $courses = Course::query()
        ->with(['domain:id,name', 'teacher:id,name'])
        ->withCount([
            'purchases as enrollments_count' => fn ($q) => $q->where('status', 'completed'),
        ])
        ->withSum([
            'purchases as revenue_sum' => fn ($q) => $q->where('status', 'completed'),
        ], 'amount_total')
        ->orderByDesc($by === 'enrollments' ? 'enrollments_count' : 'revenue_sum')
        ->limit($limit)
        ->get()
        ->map(fn (Course $course) => [
            'id'          => $course->id,
            'title'       => $course->title,
            'image'       => $course->thumbnail,
            'status'      => $course->status,
            'domain'      => $course->domain?->name,
            'teacher'     => $course->teacher?->name,
            'enrollments' => (int) $course->enrollments_count,
            'revenue'     => (float) ($course->revenue_sum ?? 0),
        ]);

    return $this->success($courses, 'Top cours récupérés avec succès.');
}

    /**
     * Calcule un delta de croissance en % entre deux valeurs.
     *
     * Formule standard : ((current - previous) / previous) * 100.
     *
     * Cas limite géré explicitement : si previous = 0, la division est
     * mathématiquement impossible. On ne retourne PAS "0%" (faux, sous-entend
     * "pas de changement") ni un nombre inventé. On retourne `growth_percent = null`
     * et c'est au frontend de décider comment l'afficher (ex: badge "Nouveau"
     * si current > 0, ou masquer le badge si current = 0 aussi).
     */
    private function growthMetric(float $current, float $previous): array
    {
        $growthPercent = null;

        if ($previous > 0) {
            $growthPercent = round((($current - $previous) / $previous) * 100, 1);
        }

        return [
            'current'        => $current,
            'previous'       => $previous,
            'growth_percent' => $growthPercent,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | GESTION DES UTILISATEURS
    |--------------------------------------------------------------------------
    */

   public function users(Request $request): JsonResponse
{
    $validator = Validator::make($request->all(), [
        'role'            => ['nullable', Rule::in(['student', 'teacher', 'admin'])],
        'status'          => ['nullable', Rule::in(['active', 'inactive'])],
        'search'          => ['nullable', 'string', 'max:255'],
        'per_page'        => ['nullable', 'integer', 'min:1', 'max:100'],
        'sort_by'         => ['nullable', Rule::in(['name', 'created_at', 'email', 'total_earnings'])],
        'sort_direction'  => ['nullable', Rule::in(['asc', 'desc'])],
    ]);

    if ($validator->fails()) {
        return $this->validationError($validator);
    }

    $sortBy = $request->input('sort_by', 'created_at');
    $sortDirection = $request->input('sort_direction', 'desc');

    $users = User::query()
        ->where('id', '!=', $request->user()->id) // ✅ exclut l'admin connecté
        ->when($request->filled('role'), fn ($q) => $q->where('role', $request->role))
        ->when($request->filled('status'), fn ($q) => $q->where('is_active', $request->status === 'active'))
        ->when($request->filled('search'), function ($q) use ($request) {
            $search = $request->string('search');
            $q->where(fn ($qq) => $qq->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%"));
        })
        ->orderBy($sortBy, $sortDirection)
        ->paginate($request->integer('per_page', 20));

    return $this->success($users, 'Liste des utilisateurs récupérée avec succès.');
}

    public function showUser(User $user): JsonResponse
    {
        $user->loadCount(['coursesAsStudent' => fn ($q) => $q]);

        return $this->success($user, 'Détails de l\'utilisateur récupérés avec succès.');
    }

    /**
     * Active ou désactive un compte utilisateur.
     */
    public function toggleUserStatus(Request $request, User $user): JsonResponse
    {
        if (Gate::denies('adminDeactivate', $user)) {
            return $this->forbidden("Vous n'êtes pas autorisé à modifier le statut de cet utilisateur.");
        }

        if ($user->id === $request->user()->id) {
            return $this->error("Vous ne pouvez pas désactiver votre propre compte.", 422);
        }

        $user->is_active = ! $user->is_active;
        $user->save();

        $label = $user->is_active ? 'activé' : 'désactivé';

        return $this->success($user, "Le compte de {$user->name} a bien été {$label}.");
    }

    /**
     * Modifie le rôle d'un utilisateur (ex: promouvoir en admin, rétrograder en student...).
     */
    public function updateUserRole(Request $request, User $user): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'role' => ['required', Rule::in(['student', 'teacher', 'admin'])],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        if ($user->id === $request->user()->id) {
            return $this->error("Vous ne pouvez pas modifier votre propre rôle.", 422);
        }

        $oldRole = $user->role;
        $user->role = $request->string('role');
        $user->save();

        return $this->success($user, "Le rôle de {$user->name} a été changé de « {$oldRole} » à « {$user->role} ».");
    }

    /**
     * Supprime définitivement un utilisateur.
     * Les données liées (courses, purchases...) sont gérées via cascadeOnDelete en DB.
     */
    public function deleteUser(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            return $this->error("Vous ne pouvez pas supprimer votre propre compte.", 422);
        }

        if ($user->role === 'admin') {
            return $this->error("Impossible de supprimer un compte administrateur depuis cette action.", 422);
        }

        try {
            DB::transaction(function () use ($user) {
                $user->delete();
            });
        } catch (Throwable $e) {
            Log::error('Erreur suppression utilisateur', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return $this->error("Une erreur est survenue lors de la suppression de l'utilisateur.", 500);
        }

        return $this->success(null, "L'utilisateur a été supprimé avec succès.");
    }

    /*
    |--------------------------------------------------------------------------
    | GESTION DES DEMANDES TEACHER
    |--------------------------------------------------------------------------
    */

    public function teacherRequests(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status'   => ['nullable', Rule::in(['pending', 'approved', 'rejected'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $requests = TeacherRequest::with(['user', 'domain'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return $this->success($requests, 'Liste des demandes teacher récupérée avec succès.');
    }

    /**
     * Approuve une demande teacher : passe le user en role=teacher + status=approved.
     */
    public function approveTeacherRequest(TeacherRequest $teacherRequest): JsonResponse
    {
        if ($teacherRequest->status !== 'pending') {
            return $this->error("Cette demande a déjà été traitée (statut actuel : {$teacherRequest->status}).", 422);
        }

        try {
            DB::transaction(function () use ($teacherRequest) {
                $teacherRequest->update(['status' => 'approved']);
                $teacherRequest->user()->update(['role' => 'teacher']);
            });
        } catch (Throwable $e) {
            Log::error('Erreur approbation teacher request', ['id' => $teacherRequest->id, 'error' => $e->getMessage()]);

            return $this->error("Une erreur est survenue lors de l'approbation de la demande.", 500);
        }

        return $this->success(
            $teacherRequest->fresh(['user', 'domain']),
            "La demande de {$teacherRequest->user->name} a été approuvée. Il est désormais teacher."
        );
    }

    /**
     * Rejette une demande teacher avec un commentaire admin obligatoire.
     */
    public function rejectTeacherRequest(Request $request, TeacherRequest $teacherRequest): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'admin_comment' => ['required', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        if ($teacherRequest->status !== 'pending') {
            return $this->error("Cette demande a déjà été traitée (statut actuel : {$teacherRequest->status}).", 422);
        }

        $teacherRequest->update([
            'status'        => 'rejected',
            'admin_comment' => $request->string('admin_comment'),
        ]);

        return $this->success($teacherRequest, 'La demande a été rejetée avec succès.');
    }

    /*
    |--------------------------------------------------------------------------
    | GESTION DES DOMAINES (catégories)
    |--------------------------------------------------------------------------
    */

    public function domains(): JsonResponse
    {
        $domains = Domain::withCount('courses')->latest()->get();

        return $this->success($domains, 'Liste des domaines récupérée avec succès.');
    }

    public function storeDomain(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'image'       => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $domain = Domain::create([
            'name'        => $request->string('name'),
            'slug'        => $this->uniqueSlug(Domain::class, $request->string('name')),
            'description' => $request->input('description'),
            'image'       => $request->string('image'),
        ]);

        return $this->success($domain, 'Le domaine a été créé avec succès.', 201);
    }

    public function updateDomain(Request $request, Domain $domain): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name'        => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'image'       => ['sometimes', 'required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        if ($request->filled('name') && $request->string('name') != $domain->name) {
            $domain->slug = $this->uniqueSlug(Domain::class, $request->string('name'), $domain->id);
        }

        $domain->fill($request->only(['name', 'description', 'image']));
        $domain->save();

        return $this->success($domain, 'Le domaine a été mis à jour avec succès.');
    }

    public function deleteDomain(Domain $domain): JsonResponse
    {
        if ($domain->courses()->exists()) {
            return $this->error("Impossible de supprimer ce domaine : des cours y sont encore rattachés.", 422);
        }

        $domain->delete();

        return $this->success(null, 'Le domaine a été supprimé avec succès.');
    }

    /*
    |--------------------------------------------------------------------------
    | GESTION DES COURS (modération)
    |--------------------------------------------------------------------------
    */

    public function courses(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status'     => ['nullable', Rule::in(['draft', 'published', 'archived'])],
            'domain_id'  => ['nullable', 'integer', 'exists:domains,id'],
            'teacher_id' => ['nullable', 'integer', 'exists:users,id'],
            'per_page'   => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $courses = Course::with(['domain', 'teacher'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('domain_id'), fn ($q) => $q->where('domain_id', $request->domain_id))
            ->when($request->filled('teacher_id'), fn ($q) => $q->where('teacher_id', $request->teacher_id))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return $this->success($courses, 'Liste des cours récupérée avec succès.');
    }

    public function showCourse(Course $course): JsonResponse
    {
        $course->load(['domain', 'teacher', 'modules.lessons']);

        return $this->success($course, 'Détails du cours récupérés avec succès.');
    }

    /**
     * Change le statut d'un cours (draft / published / archived).
     * Action de modération admin (ex: archiver un cours signalé).
     */
    public function updateCourseStatus(Request $request, Course $course): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => ['required', Rule::in(['draft', 'published', 'archived'])],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $oldStatus = $course->status;
        $course->status = $request->string('status');
        $course->save();

        return $this->success($course, "Le statut du cours est passé de « {$oldStatus} » à « {$course->status} ».");
    }

    public function deleteCourse(Course $course): JsonResponse
    {
        try {
            DB::transaction(function () use ($course) {
                $course->delete();
            });
        } catch (Throwable $e) {
            Log::error('Erreur suppression cours', ['course_id' => $course->id, 'error' => $e->getMessage()]);

            return $this->error("Une erreur est survenue lors de la suppression du cours.", 500);
        }

        return $this->success(null, 'Le cours a été supprimé avec succès.');
    }

    /*
    |--------------------------------------------------------------------------
    | FINANCE - Purchases & Commissions
    |--------------------------------------------------------------------------
    */

    public function purchases(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status'   => ['nullable', Rule::in(['pending', 'completed', 'refunded', 'failed'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $purchases = CoursePurchase::with(['course', 'student', 'teacher'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return $this->success($purchases, 'Liste des achats récupérée avec succès.');
    }

    public function commissions(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status'   => ['nullable', Rule::in(['pending', 'paid', 'refunded'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $commissions = Commission::with(['teacher', 'course', 'purchase'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return $this->success($commissions, 'Liste des commissions récupérée avec succès.');
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS - Réponses standardisées
    |--------------------------------------------------------------------------
    */

    private function success(mixed $data, string $message, int $code = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $code);
    }

    private function error(string $message, int $code = 400): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $code);
    }

    private function forbidden(string $message = "Action non autorisée."): JsonResponse
    {
        return $this->error($message, 403);
    }

    private function validationError(\Illuminate\Contracts\Validation\Validator $validator): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => "Les données fournies sont invalides.",
            'errors'  => $validator->errors(),
        ], 422);
    }

    private function uniqueSlug(string $modelClass, string $name, ?int $ignoreId = null): string
    {
        $slug = Str::slug($name);
        $original = $slug;
        $i = 1;

        while (
            $modelClass::where('slug', $slug)
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = "{$original}-{$i}";
            $i++;
        }

        return $slug;
    }
}
