<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\QuizAttempt;
use Illuminate\Http\Request;
use App\Models\Course;
use App\Models\Module;
use App\Models\Lesson;
use App\Models\LessonBlock;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class CourseController extends Controller
{
    /**
     * Display a listing of the resource.
     */
public function index(Request $request)
{
    $perPage = $request->input('per_page', 12);

    $paginator = Course::query()
        ->with(['domain:id,name,slug', 'teacher:id,name'])
        ->select(['id','title','slug','level','language','price','is_free','status','thumbnail','domain_id','teacher_id','created_at'])
        ->when($request->search,     fn($q) => $q->where('title', 'like', "%{$request->search}%"))
        ->when($request->level,      fn($q) => $q->where('level', $request->level))
        ->when($request->language,   fn($q) => $q->where('language', $request->language))
        ->when($request->has('is_free'), fn($q) => $q->where('is_free', filter_var($request->is_free, FILTER_VALIDATE_BOOLEAN)))
        ->when($request->filled('min_price'), fn($q) =>
            $q->where('price', '>=', (float) $request->min_price)
        )

        ->when($request->filled('max_price'), fn($q) =>
            $q->where('price', '<=', (float) $request->max_price)
        )
        ->when($request->filled('domain'), fn($q) =>
        $q->whereHas('domain', fn($sub) =>
        is_numeric($request->domain)
            ? $sub->where('id', $request->domain)
            : $sub->where('slug', $request->domain)
    )
)
        ->latest('created_at')
        ->paginate($perPage)
        ->withQueryString();

    return response()->json([
        'data' => $paginator->map(fn($course) => [
            'id'          => $course->id,
            'title'       => $course->title,
            'slug'        => $course->slug,
            'level'       => $course->level,
            'language'    => $course->language,
            'price'       => $course->price,
            'is_free'     => $course->is_free,
            'status'      => $course->status,
             'thumbnail' => $course->thumbnail
                ? '/api/proxy/storage/' . $course->thumbnail
                : null,
            'domain'      => $course->domain?->name,
            'domain_slug' => $course->domain?->slug ,
            'teacher'     => $course->teacher?->name,
            'created_at'  => $course->created_at->toDateTimeString(),
        ])->values()->toArray(),

        'current_page' => $paginator->currentPage(),
        'last_page'    => $paginator->lastPage(),
        'per_page'     => $paginator->perPage(),
        'total'        => $paginator->total(),
    ]);
}
    /**
     * Créer un nouveau cours avec structure multi-étapes (setup, modules, lessons, blocks)
     */
    private function validateCourseCreation(Request $request): array
    {
            $rawModules = $request->input('modules');

    if (is_string($rawModules)) {
        $decoded = json_decode($rawModules, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            abort(422, 'Le champ modules contient un JSON invalide.');
        }

        // Écrase complètement la clé modules dans le sac de requête
        $request->request->set('modules', $decoded);
    }

        return $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'required|string',
            'level'       => 'required|in:beginner,intermediate,advanced',
            'language' => [
                'required',
                'string',
                Rule::in(['fr', 'en', 'es', 'de', 'it', 'ar']), // Liste des langues supportées
            ],
            'is_free'     => 'required|boolean',
            // Price: required only when is_free is false
            'price'       => 'required_if:is_free,false|nullable|numeric|min:0',
            'thumbnail'   => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',

            // Modules et contenu nested
            'modules'                 =>  'required|array|min:1',
            'modules.*.title'         => 'required|string|max:255',
            'modules.*.description'   => 'nullable|string',
            'modules.*.order'         => 'nullable|integer|min:1',

            'modules.*.lessons'                 => 'required|array|min:1',
            'modules.*.lessons.*.title'         => 'required|string|max:255',
            'modules.*.lessons.*.is_preview'    => 'nullable|boolean',
            'modules.*.lessons.*.order'         => 'nullable|integer|min:1',

            'modules.*.lessons.*.blocks'                 =>'required|array|min:1',
            'modules.*.lessons.*.blocks.*.order'         => 'nullable|integer|min:1',
            'modules.*.lessons.*.blocks.*.is_preview'    => 'nullable|boolean',
            'modules.*.lessons.*.blocks.*.content'       => 'nullable|string',
            'modules.*.lessons.*.blocks.*.media_url'     => 'nullable',
            'modules.*.lessons.*.blocks.*.duration_seconds' => 'nullable|integer|min:0',
            'modules.*.lessons.*.blocks.*.language'      => 'nullable|string|max:100',
            'modules.*.lessons.*.blocks.*.code_data'     => 'nullable|json',

            // Validation stricte du Type de Bloc
            'modules.*.lessons.*.blocks.*.type'          => ['required', Rule::in([
                'heading', 'paragraph', 'list', 'quote', 'image', 'video',
                'audio', 'file', 'code', 'quiz', 'embed', 'divider', 'callout'
            ])],


       
            // 1. VALIDATION DES SETTINGS (Sous-types : headings, lists, callouts)
            'modules.*.lessons.*.blocks.*.settings' => 'nullable|array',
            // Si le type est 'heading', le niveau h1-h6 est requis
            'modules.*.lessons.*.blocks.*.settings.level' => [
                'required_if:modules.*.lessons.*.blocks.*.type,heading',
                'nullable', 'string', 'in:h1,h2,h3,h4,h5,h6'
            ],
            // Si le type est 'list', le style (ordered/unordered) est requis
            'modules.*.lessons.*.blocks.*.settings.style' => [
                'required_if:modules.*.lessons.*.blocks.*.type,list',
                'nullable', 'string', 'in:ordered,unordered'
            ],
            // Si le type est 'callout', le statut visuel est requis
            'modules.*.lessons.*.blocks.*.settings.type' => [
                'required_if:modules.*.lessons.*.blocks.*.type,callout',
                'nullable', 'string', 'in:info,success,warning,danger'
            ],
            // 2. VALIDATION STRICTE DU QUIZ (quiz_data)
            'modules.*.lessons.*.blocks.*.quiz_data' => 'nullable|array',

            'modules.*.lessons.*.blocks.*.settings.quiz_title' => [
            'required_if:modules.*.lessons.*.blocks.*.type,quiz',
            'nullable', 'string', 'max:255'
        ],
        
                // Description du quiz (obligatoire si le bloc est de type quiz)
                'modules.*.lessons.*.blocks.*.settings.quiz_description' => [
                    'required_if:modules.*.lessons.*.blocks.*.type,quiz',
                    'nullable', 'string', 'max:1000'
                ],
            // Score minimum pour réussir le quiz
            'modules.*.lessons.*.blocks.*.quiz_data.settings.passing_score_percent' => 'required_with:modules.*.lessons.*.blocks.*.quiz_data|integer|min:0|max:100',
            

             'modules.*.lessons.*.blocks.*.quiz_data.settings.shuffle_questions' => 'nullable|boolean',
            'modules.*.lessons.*.blocks.*.quiz_data.settings.show_explanation_after_submit' => 'nullable|boolean',
            'modules.*.lessons.*.blocks.*.quiz_data.settings.show_correct_answers_after_submit' => 'nullable|boolean',
            'modules.*.lessons.*.blocks.*.quiz_data.settings.time_limit_minutes' => 'nullable|integer|min:1|max:180',
            'modules.*.lessons.*.blocks.*.quiz_data.settings.max_attempts' => 'nullable|integer|min:1|max:10',

            // Les questions
            'modules.*.lessons.*.blocks.*.quiz_data.questions' => 'required_with:modules.*.lessons.*.blocks.*.quiz_data|array|min:1',
            'modules.*.lessons.*.blocks.*.quiz_data.questions.*.id' => 'required|string',
            'modules.*.lessons.*.blocks.*.quiz_data.questions.*.question_text' => 'required|string|max:1000',
            'modules.*.lessons.*.blocks.*.quiz_data.questions.*.type' => 'required|in:single,multiple', // Choix unique ou choix multiple

            // Les options de réponses possibles pour chaque question
            'modules.*.lessons.*.blocks.*.quiz_data.questions.*.options' => 'required|array|min:2',
            'modules.*.lessons.*.blocks.*.quiz_data.questions.*.options.*.id' => 'required|string',
            'modules.*.lessons.*.blocks.*.quiz_data.questions.*.options.*.text' => 'required|string|max:255',

            // Les bonnes réponses (Tableau contenant les IDs des options correctes)
            'modules.*.lessons.*.blocks.*.quiz_data.questions.*.correct_answer' => 'required|array|min:1',
            'modules.*.lessons.*.blocks.*.quiz_data.questions.*.explanation' => 'nullable|string|max:1000',
        ]);
    }
    private function createCourse(array $data, $user, int $domainId): Course
    {
        $isFree = $data['is_free'] ?? false;
        $price = $isFree ? 0 : ($data['price'] ?? 0);

        return Course::create([
            'title'       => $data['title'] ?? 'Sans titre',
            'slug'        => Str::slug($data['title']) . '-' . Str::random(6),
            'description' => $data['description'] ?? null,
            'level'       => $data['level'] ?? 'beginner',
            'language'    => $data['language'] ?? 'fr',
            'price'       => $price,
            'is_free'     => $isFree,
            'domain_id'   => $domainId,
            'teacher_id'  => $user->id,
            'status'      => 'draft',
        ]);
    }
    private function attachThumbnailIfPresent(Request $request, Course $course): void
    {
        if (!$request->hasFile('thumbnail')) {
            return;
        }

        $path = $request->file('thumbnail')->store('courses', 'public');
        $course->update(['thumbnail' => $path]);
    }
 private function createModulesAndNestedContent(Request $request, array $modulesData, Course $course): void
{
    foreach ($modulesData as $moduleIndex => $moduleData) {
        $module = Module::create([
            'course_id'   => $course->id,
            'title'       => $moduleData['title'],
            'description' => $moduleData['description'] ?? null,
            'order'       => $moduleData['order'] ?? ($moduleIndex + 1),
        ]);

        foreach ($moduleData['lessons'] ?? [] as $lessonIndex => $lessonData) {
            $lesson = Lesson::create([
                'module_id'  => $module->id,
                'title'      => $lessonData['title'],
                'slug'       => Str::slug($lessonData['title']) . '-' . Str::random(6),
                'order'      => $lessonData['order'] ?? ($lessonIndex + 1),
                'is_preview' => $lessonData['is_preview'] ?? false,
            ]);

            foreach ($lessonData['blocks'] ?? [] as $blockIndex => $blockData) {
                $mediaPath = $blockData['media_url'] ?? null; // URL externe si présente

                // === GESTION DU FICHIER UPLOADÉ ===
                // ⚠️ Notation par POINTS, pas par crochets, pour hasFile()/file()
                $fileKey = "modules.{$moduleIndex}.lessons.{$lessonIndex}.blocks.{$blockIndex}.media_url";

                if ($request->hasFile($fileKey)) {
                    $file = $request->file($fileKey);
                    $extension = $file->getClientOriginalExtension();
                    $filename = Str::random(25) . '.' . $extension;

                    $blockTypePlural = Str::plural($blockData['type']);
                    $folder = "media/{$blockTypePlural}/" . now()->year . '/' . sprintf('%02d', now()->month);

                    $mediaPath = $file->storeAs($folder, $filename, 'public');
                }

                LessonBlock::create([
                    'lesson_id'        => $lesson->id,
                    'type'             => $blockData['type'],
                    'content'          => $blockData['content'] ?? null,
                    'media_url'        => $mediaPath,
                    'settings'         => $blockData['settings'] ?? null,
                    'duration_seconds' => $blockData['duration_seconds'] ?? null,
                    'language'         => $blockData['language'] ?? 'fr',
                    'quiz_data'        => $blockData['quiz_data'] ?? null,
                    'code_data'        => $blockData['code_data'] ?? null,
                    'order'            => $blockData['order'] ?? ($blockIndex + 1),
                    'is_preview'       => $blockData['is_preview'] ?? false,
                ]);
            }
        }
    }
}

    private function formatCourseResponse(Course $course): array
    {
        $course->load(['domain', 'teacher']);

        return [
            'id'          => $course->id,
            'title'       => $course->title,
            'slug'        => $course->slug,
            'description' => $course->description,
            'thumbnail'   => $course->thumbnail ? Storage::url($course->thumbnail) : null,
            'level'       => $course->level,
            'language'    => $course->language,
            'price'       => $course->price,
            'is_free'     => $course->is_free,
            'status'      => $course->status,
            'domain'      => $course->domain?->name,
            'teacher'     => $course->teacher?->name,
            'created_at'  => $course->created_at->toDateTimeString(),
        ];
    }
    public function store(Request $request)
    {

        $user = $request->user();

        if (!$user->isTeacher()) {
            return response()->json(['message' => 'Seuls les enseignants peuvent créer des cours'], 403);
        }

        $teacherRequest = $user->teacherRequest;
        if (!$teacherRequest || !$teacherRequest->isApproved()) {
            return response()->json(['message' => 'Votre demande de formateur doit être approuvée pour créer un cours'], 403);
        }

        $validated = $this->validateCourseCreation($request);
        return DB::transaction(function () use ($request, $validated, $user, $teacherRequest) {
           $course = $this->createCourse($validated, $user, $teacherRequest->domain_id);
           $this->attachThumbnailIfPresent($request, $course);
           $this->createModulesAndNestedContent($request, $validated['modules'] ?? [], $course);
           return response()->json([
                'message' => 'Cours créé avec succès',
                'course' => $this->formatCourseResponse($course),
            ], 201);
        });



    }

  /**
 * Afficher un cours complet avec toute sa structure nested
 * (course → modules → lessons → blocks)
 */
public function show(Course $course)
    {
        $course->load([
            'domain:id,name,slug',
            'teacher:id,name',
            'modules' => function ($query) {
                $query->orderBy('order')->with([
                    'lessons' => function ($q) {
                        $q->orderBy('order')->with([
                            'blocks' => function ($qb) {
                                $qb->orderBy('order');
                            }
                        ]);
                    }
                ]);
            }
        ]);

        return response()->json([
            'id'          => $course->id,
            'title'       => $course->title,
            'slug'        => $course->slug,
            'description' => $course->description,
            'level'       => $course->level,
            'language'    => $course->language,
            'price'       => $course->price,
            'is_free'     => $course->is_free,
            'status'      => $course->status,
            'thumbnail'   => $course->thumbnail ? '/api/proxy/storage/' . $course->thumbnail : null,
            'domain'      => $course->domain ? [
                'id'   => $course->domain->id,
                'name' => $course->domain->name,
                'slug' => $course->domain->slug,
            ] : null,
            'teacher'     => $course->teacher ? [
                'id'   => $course->teacher->id,
                'name' => $course->teacher->name,
            ] : null,
            'created_at'  => $course->created_at->toDateTimeString(),
            'updated_at'  => $course->updated_at->toDateTimeString(),
            'modules' => $course->modules->map(function ($module) {
                return [
                    'id'          => $module->id,
                    'title'       => $module->title,
                    'description' => $module->description,
                    'order'       => $module->order,
                    'created_at'  => $module->created_at->toDateTimeString(),
                    'lessons' => $module->lessons->map(function ($lesson) {
                        return [
                            'id'          => $lesson->id,
                            'title'       => $lesson->title,
                            'slug'        => $lesson->slug,
                            'order'       => $lesson->order,
                            'is_preview'  => $lesson->is_preview,
                            'created_at'  => $lesson->created_at->toDateTimeString(),
                            'blocks' => $lesson->blocks->map(function ($block) {

                                $quizData = $block->quiz_data;
                                // PROTECTION ANTI-TRICHE : Supprimer les réponses si le bloc est un quiz
                                if ($block->type === 'quiz' && isset($quizData['questions'])) {
                                    foreach ($quizData['questions'] as &$question) {
                                        unset($question['correct_answer']);
                                        unset($question['explanation']);
                                    }
                                }

                                return [
                                    'id'               => $block->id,
                                    'type'             => $block->type,
                                    'content'          => $block->content,
                                    'media_url'        => $block->media_url ? '/api/proxy/storage/' . $block->media_url : null,
                                    'settings'         => $block->settings,
                                    'duration_seconds' => $block->duration_seconds,
                                    'language'         => $block->language,
                                    'quiz_data'        => $quizData,
                                    'code_data'        => $block->code_data,
                                    'order'            => $block->order,
                                    'is_preview'       => $block->is_preview,
                                    'created_at'       => $block->created_at->toDateTimeString(),
                                ];
                            })->values()->toArray(),
                        ];
                    })->values()->toArray(),
                ];
            })->values()->toArray(),
        ]);
    }

    /**
 * Afficher un cours complet par son slug
 */
public function showBySlug(Course $course)
{
    // $course = Course::where('slug', $slug)->firstOrFail();

    // Réutiliser la méthode existante pour charger la structure
    return $this->show($course);
}

    /**
 * Récupère les cours créés par l'enseignant connecté (pour la page de gestion)
 */
public function myCourses(Request $request)
{
    $user = $request->user();

    // Vérification des droits
    if (!$user || !$user->isTeacher()) {
        return response()->json([
            'message' => 'Accès refusé. Vous devez être enseignant.'
        ], 403);
    }

    $perPage = $request->input('per_page', 12);

    $paginator = Course::query()
        ->where('teacher_id', $user->id)
        ->with(['domain:id,name,slug'])
        ->select([
            'id', 'title', 'slug', 'level', 'language',
            'price', 'is_free', 'status', 'thumbnail',
            'domain_id', 'created_at', 'updated_at'
        ])
        ->when($request->search, fn($q) => $q->where('title', 'like', "%{$request->search}%"))
        ->when($request->level, fn($q) => $q->where('level', $request->level))
        ->when($request->status, fn($q) => $q->where('status', $request->status))
        ->when($request->has('is_free'), fn($q) =>
            $q->where('is_free', filter_var($request->is_free, FILTER_VALIDATE_BOOLEAN))
        )
        ->latest('created_at')
        ->paginate($perPage)
        ->withQueryString();

    return response()->json([
        'data' => $paginator->map(fn($course) => [
            'id'          => $course->id,
            'title'       => $course->title,
            'slug'        => $course->slug,
            'level'       => $course->level,
            'language'    => $course->language,
            'price'       => $course->price,
            'is_free'     => $course->is_free,
            'status'      => $course->status,
            'thumbnail'   => $course->thumbnail
                ? '/api/proxy/storage/' . $course->thumbnail
                : null,
            'domain'      => $course->domain?->name,
            'domain_slug' => $course->domain?->slug,
            'created_at'  => $course->created_at->toDateTimeString(),
            'updated_at'  => $course->updated_at->toDateTimeString(),
        ])->values()->toArray(),

        'current_page' => $paginator->currentPage(),
        'last_page'    => $paginator->lastPage(),
        'per_page'     => $paginator->perPage(),
        'total'        => $paginator->total(),
    ]);
}
    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Course $course)
    {
        // Vérifier l'autorisation
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        // Vérifier si l'utilisateur est autorisé à modifier ce cours
        $isAdmin = $user->role === 'admin';
        $isTeacher = $user->role === 'teacher';
        $isOwner = $user->id === $course->teacher_id;

        if (!($isAdmin || ($isTeacher && $isOwner))) {
            return response()->json([
                'message' => 'Vous n\'êtes pas autorisé à modifier ce cours. Seuls les administrateurs ou le créateur du cours peuvent le faire.'
            ], 403);
        }

        // Validation des données
        $validated = $request->validate([
            'title'       => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'level'       => 'sometimes|in:beginner,intermediate,advanced',
            'language'    => 'sometimes|string|max:100',
            'price'       => 'sometimes|numeric|min:0',
            'is_free'     => 'sometimes|boolean',
            'domain_id'   => 'sometimes|exists:domains,id',
            'status'      => 'sometimes|in:draft,published,archived',
            'thumbnail'   => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048', // 2MB max
        ]);

        if ($request->hasFile('thumbnail')) {
            if ($course->thumbnail) {
                Storage::disk('public')->delete($course->thumbnail);
            }

            $thumbnailPath = $request->file('thumbnail')->store('courses', 'public');
            $validated['thumbnail'] = $thumbnailPath;
        }

        if (isset($validated['title'])) {
            $validated['slug'] = Str::slug($validated['title']) . '-' . Str::random(6);
        }

        if (isset($validated['is_free']) && $validated['is_free'] === true) {
            $validated['price'] = 0;
        } elseif (isset($validated['is_free']) && $validated['is_free'] === false) {
            if (!isset($validated['price']) && $course->price == 0) {
                $validated['price'] = 0;
            }
        }

        $course->update(array_filter($validated));

        $course->refresh()->load(['domain', 'teacher']);

        return response()->json([
            'message' => 'Cours mis à jour avec succès',
            'course'  => [
                'id'          => $course->id,
                'title'       => $course->title,
                'slug'        => $course->slug,
                'description' => $course->description,
                'thumbnail'   => $course->thumbnail ? Storage::url($course->thumbnail) : null,
                'level'       => $course->level,
                'language'    => $course->language,
                'price'       => $course->price,
                'is_free'     => $course->is_free,
                'status'      => $course->status,
                'domain'      => $course->domain ? $course->domain->name : null,
                'teacher'     => $course->teacher ? $course->teacher->name : null,
                'updated_at'  => $course->updated_at->toDateTimeString(),
            ]
        ], 200);
    }

    private function calculateQuestionPoints(int $totalQuestions, float $totalScore = 100): float
    {
        if ($totalQuestions === 0) return 0;
        
        // Points par question = Score total / Nombre de questions
        return round($totalScore / $totalQuestions, 2);
    }

    private function isAnswerCorrect(array $question, array $selectedOptions): bool
    {
        $correctAnswers = $question['correct_answer'] ?? [];
        
        // Si la question n'a pas de réponse correcte définie
        if (empty($correctAnswers)) {
            return false;
        }
        
        // Trier les tableaux pour une comparaison équitable
        sort($selectedOptions);
        sort($correctAnswers);
        
        // Si le type est 'single', une seule réponse est attendue
        if ($question['type'] === 'single') {
            return count($selectedOptions) === 1 && 
                   count($correctAnswers) === 1 && 
                   $selectedOptions[0] === $correctAnswers[0];
        }
        
        // Pour 'multiple', comparer les tableaux complets
        return $selectedOptions === $correctAnswers;
    }

    

    public function submitQuiz(Request $request, Course $course, Lesson $lesson, LessonBlock $block)
{
    $user = $request->user();

    if (!$user) {
        return response()->json(['message' => 'Non authentifié'], 401);
    }

    if ($lesson->module->course_id !== $course->id || $block->lesson_id !== $lesson->id) {
        return response()->json(['message' => 'Ressource introuvable'], 404);
    }

    if ($block->type !== 'quiz') {
        return response()->json(['message' => 'Ce bloc n\'est pas un quiz'], 400);
    }

    // Valider les données soumises
    $validated = $request->validate([
        'answers' => 'required|array',
        'answers.*.question_id' => 'required|string',
        'answers.*.selected_options' => 'required|array|min:1',
        'started_at' => 'required|date',
    ]);

    $quizData = $block->quiz_data;
    $questions = $quizData['questions'] ?? [];
    $userAnswers = collect($validated['answers'])->keyBy('question_id');

    // Vérifier les tentatives
    $attemptsCount = QuizAttempt::where([
        'user_id' => $user->id,
        'block_id' => $block->id,
    ])->count();

    $maxAttempts = $quizData['settings']['max_attempts'] ?? null;
    if ($maxAttempts && $attemptsCount >= $maxAttempts) {
        return response()->json([
            'message' => 'Vous avez atteint le nombre maximum de tentatives pour ce quiz',
            'max_attempts' => $maxAttempts,
            'attempts_made' => $attemptsCount,
        ], 403);
    }

    // === CALCUL DE LA DURÉE ===
    // On fait confiance à l'heure de début envoyée par le front, mais on
    // borne la valeur pour éviter toute manipulation (durée négative ou aberrante)
    $startedAt = \Carbon\Carbon::parse($validated['started_at']);
    $completedAt = now();

    $durationSeconds = $startedAt->diffInSeconds($completedAt, false);

    // Protection : durée négative (horloge client décalée) → on force à 0
    if ($durationSeconds < 0) {
        $durationSeconds = 0;
    }

    // Protection : si un time_limit_minutes est défini, on plafonne la durée enregistrée
    $timeLimitMinutes = $quizData['settings']['time_limit_minutes'] ?? null;
    if ($timeLimitMinutes && $durationSeconds > ($timeLimitMinutes * 60)) {
        $durationSeconds = $timeLimitMinutes * 60;
    }

    // Configuration du score
    $totalQuestions = count($questions);
    $totalScore = 100;
    $pointsPerQuestion = $this->calculateQuestionPoints($totalQuestions, $totalScore);

    $results = [];
    $score = 0;
    $correctCount = 0;
    $totalPoints = 0;

    foreach ($questions as $index => $question) {
        $questionId = $question['id'] ?? "q_{$index}";
        $userAnswer = $userAnswers->get($questionId);
        $selectedOptions = $userAnswer['selected_options'] ?? [];

        $isCorrect = $this->isAnswerCorrect($question, $selectedOptions);
        $pointsEarned = $isCorrect ? $pointsPerQuestion : 0;

        $questionResult = [
            'id' => $questionId,
            'question_text' => $question['question_text'],
            'type' => $question['type'],
            'selected_options' => $selectedOptions,
            'is_correct' => $isCorrect,
            'points_earned' => $pointsEarned,
            'points_possible' => $pointsPerQuestion,
        ];

        if ($quizData['settings']['show_correct_answers_after_submit'] ?? true) {
            $questionResult['correct_options'] = $question['correct_answer'] ?? [];
        }

        if ($quizData['settings']['show_explanation_after_submit'] ?? true) {
            $questionResult['explanation'] = $question['explanation'] ?? null;
        }

        $results[] = $questionResult;

        if ($isCorrect) {
            $score += $pointsEarned;
            $correctCount++;
        }
        $totalPoints += $pointsPerQuestion;
    }

    $scorePercentage = $totalPoints > 0 ? round(($score / $totalPoints) * 100, 2) : 0;
    $isPassed = $scorePercentage >= ($quizData['settings']['passing_score_percent'] ?? 70);

    $attemptData = [
        'user_id' => $user->id,
        'block_id' => $block->id,
        'score' => $score,
        'score_percentage' => $scorePercentage,
        'total_questions' => $totalQuestions,
        'correct_answers' => $correctCount,
        'is_passed' => $isPassed,
        'answers' => array_map(function ($answer) {
            return [
                'question_id' => $answer['question_id'],
                'selected_options' => $answer['selected_options'],
            ];
        }, $validated['answers']),
        'started_at' => $startedAt,
        'completed_at' => $completedAt,
        'duration_seconds' => $durationSeconds,
    ];

    $attempt = QuizAttempt::create($attemptData);

    return response()->json([
        'success' => true,
        'message' => $isPassed ? 'Félicitations ! Vous avez réussi le quiz.' : 'Vous n\'avez pas atteint le score requis.',
        'attempt' => [
            'id' => $attempt->id,
            'score' => $score,
            'score_percentage' => $scorePercentage,
            'points_per_question' => $pointsPerQuestion,
            'correct_answers' => $correctCount,
            'total_questions' => $totalQuestions,
            'is_passed' => $isPassed,
            'passing_score_percent' => $quizData['settings']['passing_score_percent'] ?? 70,
            'attempt_number' => $attemptsCount + 1,
            'max_attempts' => $maxAttempts,
            'duration_seconds' => $durationSeconds,
            'completed_at' => $attempt->completed_at->toDateTimeString(),
        ],
        'results' => $results,
        'settings' => [
            'show_explanation_after_submit' => $quizData['settings']['show_explanation_after_submit'] ?? true,
            'show_correct_answers_after_submit' => $quizData['settings']['show_correct_answers_after_submit'] ?? true,
        ]
    ]);
}


    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Course $course)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        // 2. Vérifier les autorisations
        $isAdmin   = $user->role === 'admin';
        $isTeacher = $user->role === 'teacher';
        $isOwner   = $user->id === $course->teacher_id;

        if (! ($isAdmin || ($isTeacher && $isOwner))) {
            return response()->json([
                'message' => 'Vous n\'êtes pas autorisé à supprimer ce cours. Seuls les administrateurs ou le créateur du cours peuvent le faire.'
            ], 403);
        }

        if ($course->thumbnail) {
            Storage::disk('public')->delete($course->thumbnail);
        }

        $course->delete();

        return response()->json([
            'message'    => 'Cours supprimé avec succès',
            'course_id'  => $course->id,
            'deleted_at' => now()->toDateTimeString(),
        ], 200);
    }
}
