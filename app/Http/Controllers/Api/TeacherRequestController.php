<?php

namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\TeacherRequest;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

class TeacherRequestController extends Controller
{
   /**
     * Soumet une nouvelle demande d'enseignant
     */
    public function store(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        if ($user->role !== 'student') {
            return response()->json(['message' => 'Seuls les étudiants peuvent soumettre une demande'], 403);
        }

        // // Une seule demande par utilisateur
         $activeCount = TeacherRequest::where('user_id', $user->id)
            ->whereIn('status', ['pending', 'approved'])
            ->count();

        if ($activeCount > 0) {
            return response()->json([
                'message' => 'Une demande active (en attente ou approuvée) existe déjà.'
            ], 422);
        }

        try {
            $validated = $request->validate([
                'domain_id'       => ['required', 'exists:domains,id'],
                'linkedin_url'    => ['required', 'url', 'max:255'],
                'project_url'     => ['required', 'url', 'max:255'],
                'motivation'      => ['required', 'string', 'min:100', 'max:3000'],
            ]);

            $teacherRequest = TeacherRequest::create([
                'user_id'         => $user->id,
                'domain_id'       => $validated['domain_id'],
                'linkedin_url'    => $validated['linkedin_url'],
                'project_url'     => $validated['project_url'],
                'motivation'      => $validated['motivation'],
                'status'          => 'pending',
            ]);

            // Optionnel : envoyer notification email à l'admin
            // $admin = User::where('role', 'admin')->first();
            // if ($admin) {
            //     $admin->notify(new \App\Notifications\NewTeacherRequest($teacherRequest));
            // }

            return response()->json([
                'message' => 'Demande envoyée avec succès',
                'request' => $teacherRequest->only(['id', 'status', 'created_at'])
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation échouée',
                'errors'  => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Erreur serveur',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function show(Request $request, TeacherRequest $teacherRequest)
{
    $user = $request->user();

    // Vérification d'autorisation
    if (!$user || $user->role !== 'admin') {
        return response()->json([
            'success' => false,
            'message' => 'Accès non autorisé. Seuls les administrateurs peuvent consulter cette demande.'
        ], 403);
    }

    try {
        // Charger les relations avec les champs nécessaires uniquement
        $teacherRequest->load([
            'user' => function ($query) {
                $query->select('id', 'name', 'email', 'role', 'created_at');
            },
            'domain' => function ($query) {
                $query->select('id', 'name', 'slug');
            },
        ]);

        // Préparer la réponse détaillée
        $responseData = [
            'id'              => $teacherRequest->id,
            'user'            => $teacherRequest->user,
            'domain'          => $teacherRequest->domain,
            'status'          => $teacherRequest->status,
            'linkedin_url'    => $teacherRequest->linkedin_url,
            'project_url'     => $teacherRequest->project_url,
            'motivation'      => $teacherRequest->motivation,
            'admin_comment'   => $teacherRequest->admin_comment,
            'created_at'      => $teacherRequest->created_at->toDateTimeString(),
            'updated_at'      => $teacherRequest->updated_at->toDateTimeString(),
        ];

        return response()->json([
            'success' => true,
            'message' => 'Détails de la demande récupérés avec succès',
            'data'    => $responseData,
        ], 200);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Une erreur est survenue lors de la récupération de la demande',
            'error'   => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }
}

    /**
     * Liste des demandes (pour admin seulement)
     */
    public function index(Request $request)
{
    $user = $request->user();

    if (!$user || $user->role !== 'admin') {
        return response()->json([
            'success' => false,
            'message' => 'Accès non autorisé. Seuls les administrateurs peuvent accéder à cette ressource.'
        ], 403);
    }

    try {
        $perPage = $request->input('per_page', 15);
        $perPage = min(max(1, $perPage), 100);

        $requests = TeacherRequest::query()
            ->with([
                'user' => fn($q) => $q->select('id', 'name', 'email', 'role'),
                'domain' => fn($q) => $q->select('id', 'name', 'slug'),
            ])
            ->select([
                'id',
                'user_id',
                'domain_id',
                'status',
                'linkedin_url',
                'project_url',
                'motivation',
                'admin_comment',
                'created_at',
                'updated_at'
            ])
            ->latest('created_at')
            ->paginate($perPage);

        $response = [
            'data'         => $requests->items(),
            'current_page' => $requests->currentPage(),
            'last_page'    => $requests->lastPage(),
            'per_page'     => $requests->perPage(),
            'total'        => $requests->total(),
        ];

        return response()->json($response, 200);

    } catch (\Exception $e) {


        return response()->json([
            'success' => false,
            'message' => 'Erreur serveur lors de la récupération des demandes',
            'error'   => config('app.debug') ? $e->getMessage() : null
        ], 500);
    }
}

    /**
     * Met à jour le statut d'une demande (approve / reject)
     */
    public function update(Request $request, TeacherRequest $teacherRequest)
    {
        $user = $request->user();

        if (!$user || $user->role !== 'admin') {
            return response()->json(['message' => 'Accès non autorisé'], 403);
        }

        $validated = $request->validate([
            'status'        => ['required', Rule::in(['approved', 'rejected'])],
            'admin_comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $teacherRequest->update([
            'status'        => $validated['status'],
            'admin_comment' => $validated['admin_comment'] ?? null,
        ]);

        // Si approuvé → mise à jour du rôle utilisateur
        if ($validated['status'] === 'approved') {
            $teacherRequest->user->update(['role' => 'teacher']);
        }

        // Optionnel : notifier l'utilisateur
        // $teacherRequest->user->notify(new \App\Notifications\TeacherRequestStatusUpdated($teacherRequest));

        return response()->json([
            'message' => 'Statut mis à jour',
            'request' => $teacherRequest->refresh()
        ]);
    }

    /**
     * (Optionnel) Supprimer une demande
     */
    // public function destroy(TeacherRequest $teacherRequest)
    // {
    //     $user = $request->user();

    //     if (!$user || $user->role !== 'admin') {
    //         return response()->json(['message' => 'Accès non autorisé'], 403);
    //     }

    //     $teacherRequest->delete();

    //     return response()->json(['message' => 'Demande supprimée'], 200);
    // }
}
