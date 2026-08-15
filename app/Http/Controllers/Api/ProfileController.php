<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\UpdateProfileRequest;
use App\Http\Resources\User\UserProfileResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class ProfileController extends Controller
{
    /**
     * Taille max autorisée pour l'avatar (en Ko).
     */
    private const AVATAR_MAX_KB = 2048;

    /**
     * Extensions/MIME réellement acceptées (whitelist stricte, pas de confiance
     * dans l'extension fournie par le client).
     */
    private const AVATAR_ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * Afficher le profil de l'utilisateur connecté.
     * L'email et la date de naissance ne sont exposés qu'au propriétaire (voir Resource).
     */
    public function show(Request $request)
    {
        $user = $request->user();

        if ($user->role === 'teacher') {
            $user->loadCount(['coursesAsTeacher as courses_count']);
        }

        return new UserProfileResource($user);
    }

    /**
     * Afficher le profil public d'un autre utilisateur.
     * Passe par la Policy: pas de compte désactivé exposé, pas d'IDOR silencieux.
     */
    public function showPublic(Request $request, int $id)
    {
        $profile = User::findOrFail($id);

        $this->authorize('view', $profile);

        if ($profile->role === 'teacher') {
            $profile->loadCount(['coursesAsTeacher as courses_count']);
        }

        return new UserProfileResource($profile);
    }

    /**
     * Mettre à jour les informations générales du profil.
     * Validation stricte + sanitization du contenu texte libre (bio, headline).
     */
    public function update(UpdateProfileRequest $request)
    {
        $user = $request->user();
        $this->authorize('update', $user);

        $data = $request->validated();

        foreach (['headline', 'bio'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null) {
                $data[$field] = strip_tags(trim($data[$field]));
            }
        }

        $user->fill($data);
        $user->save();

        Log::info('profile.updated', ['user_id' => $user->id, 'fields' => array_keys($data)]);

        return response()->json([
            'message' => 'Profil mis à jour avec succès.',
            'data' => new UserProfileResource($user),
        ]);
    }

    /**
     * Mettre à jour la photo de profil.
     */
    public function updateAvatar(Request $request)
    {
        $user = $request->user();
        $this->authorize('update', $user);

        $request->validate([
            'profile_picture' => [
                'required',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:' . self::AVATAR_MAX_KB,
                'dimensions:min_width=100,min_height=100,max_width=4000,max_height=4000',
            ],
        ]);

        $file = $request->file('profile_picture');

        if (!in_array($file->getMimeType(), self::AVATAR_ALLOWED_MIMES, true)) {
            Log::warning('profile.avatar.rejected_mime', [
                'user_id' => $user->id,
                'declared_mime' => $file->getClientMimeType(),
                'real_mime' => $file->getMimeType(),
            ]);

            return response()->json(['message' => 'Format de fichier non autorisé.'], 422);
        }

        $extension = match ($file->getMimeType()) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        };

        $filename = Str::uuid()->toString() . '.' . $extension;
        $path = $file->storeAs('avatars', $filename, 'public');

        $oldPath = $user->profile_picture;

        $user->update(['profile_picture' => $path]);

        if ($oldPath && $oldPath !== $path && Storage::disk('public')->exists($oldPath)) {
            Storage::disk('public')->delete($oldPath);
        }

        Log::info('profile.avatar.updated', ['user_id' => $user->id]);

        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk('public');

        return response()->json([
            'message' => 'Photo de profil mise à jour.',
            'profile_picture_url' => $disk->url($path),
        ]);
    }

    public function deleteAvatar(Request $request)
    {
        $user = $request->user();
        $this->authorize('update', $user);

        if ($user->profile_picture && Storage::disk('public')->exists($user->profile_picture)) {
            Storage::disk('public')->delete($user->profile_picture);
        }

        $user->update(['profile_picture' => null]);

        Log::info('profile.avatar.deleted', ['user_id' => $user->id]);

        return response()->json(['message' => 'Photo de profil supprimée.']);
    }

    /**
     * Mettre à jour les liens sociaux.
     */
    public function updateSocialLinks(Request $request)
    {
        $user = $request->user();
        $this->authorize('update', $user);

        $validated = $request->validate([
            'social_links' => ['nullable', 'array', 'max:6'],
            'social_links.*' => ['nullable', 'url', 'starts_with:https://', 'max:255'],
        ]);

        $allowedKeys = ['linkedin', 'github', 'twitter', 'website', 'youtube', 'instagram'];
        $sanitized = collect($validated['social_links'] ?? [])
            ->only($allowedKeys)
            ->filter()
            ->toArray();

        $user->update(['social_links' => $sanitized ?: null]);

        Log::info('profile.social_links.updated', ['user_id' => $user->id]);

        return response()->json([
            'message' => 'Liens sociaux mis à jour.',
            'social_links' => $user->social_links,
        ]);
    }

    /**
     * Changer le mot de passe.
     */
    public function updatePassword(Request $request)
{
    $user = $request->user();

    $request->validate([
        'current_password' => ['required', 'current_password'],
        'password' => ['required', 'confirmed', Password::defaults()->uncompromised()],
    ]);

    DB::transaction(function () use ($user, $request) {
        $user->update(['password' => Hash::make($request->password)]);

        if (method_exists($user, 'tokens') && $request->user()->currentAccessToken()) {
            $currentTokenId = $request->user()->currentAccessToken()->id;

            // On supprime toutes les AUTRES sessions, on garde la session courante active
            $user->tokens()
                ->where('id', '!=', $currentTokenId)
                ->delete();
        }
    });

    Log::warning('profile.password.changed', [
        'user_id' => $user->id,
        'ip' => $request->ip(),
    ]);

    return response()->json(['message' => 'Mot de passe modifié. Vos autres sessions ont été déconnectées.']);
}

    /**
     * Changer l'adresse e-mail.
     */
    public function updateEmail(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'email' => ['required', 'email:rfc,dns', 'max:255', 'unique:users,email,' . $user->id],
            'current_password' => ['required', 'current_password'],
        ]);

        $oldEmail = $user->email;

        DB::transaction(function () use ($user, $validated) {
            $user->email = $validated['email'];
            $user->email_verified_at = null;
            $user->save();
        });

        $user->sendEmailVerificationNotification();

        Log::warning('profile.email.changed', [
            'user_id' => $user->id,
            'old_email' => $oldEmail,
            'new_email' => $validated['email'],
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'E-mail mis à jour. Un lien de vérification a été envoyé à la nouvelle adresse.',
        ]);
    }

    /**
     * Désactiver le compte.
     */
    public function deactivate(Request $request)
    {
        $user = $request->user();
        $this->authorize('deactivate', $user);

        $request->validate([
            'current_password' => ['required', 'current_password'],
        ]);

        DB::transaction(function () use ($user) {
            $user->update(['is_active' => false]);

            if (method_exists($user, 'tokens')) {
                $user->tokens()->delete();
            }
        });

        Log::warning('profile.deactivated', ['user_id' => $user->id, 'ip' => $request->ip()]);

        if (Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Compte désactivé.']);
    }

    /**
 * Désactiver le compte d'un autre utilisateur (action admin).
 * Pas de current_password ici : c'est l'autorité admin qui prime,
 * validée par la Policy, pas un secret partagé.
 */
public function adminDeactivate(Request $request, int $id)
{
    $admin = $request->user();
    $targetUser = User::findOrFail($id);

    $this->authorize('adminDeactivate', $targetUser);

    if ($targetUser->id === $admin->id) {
        return response()->json([
            'message' => 'Utilisez la désactivation depuis votre profil pour votre propre compte.',
        ], 422);
    }

    $validated = $request->validate([
        'reason' => ['nullable', 'string', 'max:500'],
    ]);

    DB::transaction(function () use ($targetUser, $validated) {
        $targetUser->update(['is_active' => false]);

        if (method_exists($targetUser, 'tokens')) {
            $targetUser->tokens()->delete();
        }
    });

    Log::warning('admin.user.deactivated', [
        'admin_id' => $admin->id,
        'target_user_id' => $targetUser->id,
        'reason' => $validated['reason'] ?? null,
        'ip' => $request->ip(),
    ]);

    return response()->json(['message' => 'Compte utilisateur désactivé.']);
}
}
