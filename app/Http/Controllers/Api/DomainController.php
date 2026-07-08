<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DomainController extends Controller
{
   public function getDomains()
{
    $domains = Domain::ordered()->get()->map(function ($domain) {
        $data = $domain->toArray();
        $data['image'] = $domain->image
            ? '/api/proxy/storage/' . $domain->image
            : null;
        return $data;
    });

    return response()->json($domains);
}

    /**
     * Créer un nouveau domaine (admin seulement)
     */
    public function store(Request $request)
    {
        // Vérifier si l'utilisateur est admin
        if (!$request->user()->isAdmin()) {
            return response()->json([
                'message' => 'Unauthorized. Admin access required.'
            ], 403);
        }

        // Validation des données
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:domains,name',
            'description' => 'nullable|string',
            'image' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);

        try {
            // Générer le slug automatiquement
            $slug = Str::slug($validated['name']);

            // Vérifier l'unicité du slug
            $counter = 1;
            $originalSlug = $slug;

            while (Domain::where('slug', $slug)->exists()) {
                $slug = $originalSlug . '-' . $counter;
                $counter++;
            }

            // Gérer l'upload de l'image
            $imagePath = null;
            if ($request->hasFile('image')) {
                // Stocker l'image dans storage/app/public/domains
                $imagePath = $request->file('image')->store('domains', 'public');
            }

            // Créer le domaine
            $domain = Domain::create([
                'name' => $validated['name'],
                'slug' => $slug,
                'description' => $validated['description'] ?? null,
                'image' => $imagePath,
            ]);


            return response()->json([
                'message' => 'Domain created successfully',
                'domain' => $domain
            ], 201);

        } catch (Exception $e) {
            Log::error('Error creating domain: ' . $e->getMessage());

            // Supprimer l'image uploadée en cas d'erreur
            if (isset($imagePath) && Storage::disk('public')->exists($imagePath)) {
                Storage::disk('public')->delete($imagePath);
            }

            return response()->json([
                'message' => 'Failed to create domain',
                'error' => $e->getMessage()
            ], 500);
        }
    }

   public function index(Request $request)
{
    $perPage = $request->query('per_page', 10);
    $domains = Domain::ordered()->paginate($perPage);

    $data = collect($domains->items())->map(function ($domain) {
        $item = $domain->toArray();
        $item['image'] = $domain->image
            ? '/api/proxy/storage/' . $domain->image
            : null;
        return $item;
    });

    return response()->json([
        'data'         => $data,
        'current_page' => $domains->currentPage(),
        'last_page'    => $domains->lastPage(),
        'per_page'     => $domains->perPage(),
        'total'        => $domains->total(),
    ]);
}

    /**
     * Mettre à jour un domaine existant (admin seulement)
     */
    public function update(Request $request, Domain $domain)
    {
        // Vérifier si l'utilisateur est admin
        if (!$request->user()->isAdmin()) {
            return response()->json([
                'message' => 'Unauthorized. Admin access required.'
            ], 403);
        }

        // Validation des données
        $validated = $request->validate([
            'name'        => ['sometimes', 'required', 'string', 'max:255', Rule::unique('domains', 'name')->ignore($domain->id)],
            'description' => ['nullable', 'string'],
            'image'       => ['sometimes', 'nullable', 'image', 'mimes:jpeg,png,jpg,gif,svg', 'max:2048'],
        ]);

        try {
            $dataToUpdate = [];

            // Mise à jour du nom et régénération du slug si nécessaire
            if ($request->has('name') && $request->name !== $domain->name) {
                $slug = Str::slug($validated['name']);
                $counter = 1;
                $originalSlug = $slug;

                while (Domain::where('slug', $slug)->where('id', '!=', $domain->id)->exists()) {
                    $slug = $originalSlug . '-' . $counter;
                    $counter++;
                }

                $dataToUpdate['name'] = $validated['name'];
                $dataToUpdate['slug'] = $slug;
            }

            // Mise à jour de la description si fournie
            if ($request->has('description')) {
                $dataToUpdate['description'] = $validated['description'];
            }

            // Gestion de l'image (remplacement ou suppression)
            if ($request->hasFile('image')) {
                // Supprimer l'ancienne image si elle existe
                if ($domain->image && Storage::disk('public')->exists($domain->image)) {
                    Storage::disk('public')->delete($domain->image);
                }

                // Stocker la nouvelle image
                $imagePath = $request->file('image')->store('domains', 'public');
                $dataToUpdate['image'] = $imagePath;
            }
            // Option : permettre de supprimer l'image sans en uploader une nouvelle
            elseif ($request->has('remove_image') && $request->remove_image === true) {
                if ($domain->image && Storage::disk('public')->exists($domain->image)) {
                    Storage::disk('public')->delete($domain->image);
                }
                $dataToUpdate['image'] = null;
            }

            // Mise à jour du domaine
            $domain->update($dataToUpdate);

            return response()->json([
                'message' => 'Domaine mis à jour avec succès',
                'domain'  => $domain->fresh()
            ]);

        } catch (Exception $e) {
            Log::error('Erreur lors de la mise à jour du domaine : ' . $e->getMessage());

            return response()->json([
                'message' => 'Échec de la mise à jour du domaine',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Supprimer un domaine (admin seulement)
     */
    public function destroy(Request $request, Domain $domain)
    {
        if (!$request->user()->isAdmin()) {
            return response()->json([
                'message' => 'Unauthorized. Admin access required.'
            ], 403);
        }

        try {
            if ($domain->image && Storage::disk('public')->exists($domain->image)) {
                Storage::disk('public')->delete($domain->image);
            }

            $domain->delete();

            return response()->json([
                'message' => 'Domaine supprimé avec succès'
            ]);

        } catch (Exception $e) {
            Log::error('Erreur lors de la suppression du domaine : ' . $e->getMessage());

            return response()->json([
                'message' => 'Échec de la suppression du domaine',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function show(Domain $domain)
{
    return response()->json([
        'success'     => true,
        'data'        => [
            'id'               => $domain->id,
            'name'             => $domain->name,
            'slug'             => $domain->slug,
            'description'      => $domain->description,
            'image'              => $domain->image
                ? '/api/proxy/storage/' . $domain->image
                : null,
            'created_at'       => $domain->created_at->toDateTimeString(),
            'updated_at'       => $domain->updated_at->toDateTimeString(),
        ],
    ]);
}
}
