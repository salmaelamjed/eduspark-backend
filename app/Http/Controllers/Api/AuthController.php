<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\VerifyEmailCode;
use App\Models\EmailVerificationCode;
use App\Mail\ResetPasswordLink;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AuthController extends Controller
{

public function user(Request $request)
{
    return response()->json([
        'id' => $request->user()->id,
        'name' => $request->user()->name,
        'email' => $request->user()->email,
        'role' => $request->user()->role,
        'email_verified_at' => $request->user()->email_verified_at,
    ]);
}

public function register(Request $request)
{
    $request->validate([
        'name' => 'required|string',
        'email' => 'required|email|unique:users',
        'password' => 'required|string|min:8|confirmed',
    ]);

    // Création de l'utilisateur
    $user = User::create([
        'name'     => $request->name,
        'email'    => $request->email,
        'password' => Hash::make($request->password),
        'role'     => 'student',
        'email_verified_at' => null,
    ]);

    // Générer un code OTP de 6 chiffres
    $otpCode = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    // Sauvegarder le code OTP
    EmailVerificationCode::create([
        'user_id' => $user->id,
        'code' => $otpCode,
        'expires_at' => now()->addMinutes(30),
        'used' => false,
    ]);

    try {
        $email = new VerifyEmailCode($otpCode);

        // Envoyer l'email
        Mail::to($user->email)->send($email);

        Log::info('✅ Email envoyé avec succès à: ' . $user->email);

        return response()->json([
            'success' => true,
            'message' => 'Inscription réussie ! Un code de vérification de 6 chiffres a été envoyé à votre email.',
            'user_id' => $user->id,
            'email' => $user->email,
        ], 201);

    } catch (\Exception $e) {
        return response()->json([
            'success' => true,
            'message' => 'Inscription réussie ! Veuillez vérifier votre boîte email.',
            'user_id' => $user->id,
            'email' => $user->email,
            'debug_note' => 'Code généré: ' . $otpCode,
        ], 201);
    }
}

public function verifyEmail(Request $request)
{
    $request->validate([
        'email' => 'required|email|exists:users,email',
        'code'  => 'required|string|size:6',
    ]);

    $user = User::where('email', $request->email)->first();

    if ($user->email_verified_at) {
        return response()->json([
            'success' => false,
            'message' => 'Votre email est déjà vérifié.'
        ], 400);
    }

    $verification = EmailVerificationCode::where('user_id', $user->id)
        ->where('code', $request->code)
        ->whereNull('used_at')
        ->where('expires_at', '>', now())
        ->latest()
        ->first();

    if (!$verification) {
        return response()->json([
            'success' => false,
            'message' => 'Code invalide, expiré ou déjà utilisé.'
        ], 422);
    }

    $verification->update([
        'used_at' => now()
    ]);

    $user->email_verified_at = now();
    $user->save();


    return response()->json([
        'success' => true,
        'message' => 'Email vérifié avec succès !',
        'token_type' => 'Bearer',
        'user' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
        ]
    ], 200);
}
public function resendVerificationCode(Request $request)
{
    $request->validate([
        'email' => 'required|email|exists:users,email',
    ]);

    $user = User::where('email', $request->email)->first();

    // Vérifier si l'email est déjà vérifié
    if ($user->email_verified_at) {
        return response()->json([
            'success' => false,
            'message' => 'Cet email est déjà vérifié. Vous pouvez vous connecter directement.'
        ], 400);
    }

    // Générer un nouveau code OTP de 6 chiffres
    $otpCode = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    // Sauvegarder le nouveau code dans la base de données
    EmailVerificationCode::create([
        'user_id' => $user->id,
        'code' => $otpCode,
        'expires_at' => now()->addMinutes(30), // Valable 30 minutes
        'used_at' => null,
    ]);

    // Envoyer le code par email
    try {
        Mail::to($user->email)->send(new VerifyEmailCode($otpCode));

        Log::info('Nouveau code OTP envoyé à: ' . $user->email . ' - Code: ' . $otpCode);

        return response()->json([
            'success' => true,
            'message' => 'Un nouveau code de vérification a été envoyé à votre email.',
            'email' => $user->email,
            'expires_in' => '30 minutes',
        ], 200);

    } catch (\Exception $e) {
        Log::error('Erreur lors de l\'envoi du code OTP: ' . $e->getMessage());

        // En mode debug, on peut retourner le code pour faciliter les tests
        if (config('app.debug')) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'envoi du code OTP. Voici le code généré (debug):',
                'email' => $user->email,
                'debug_code' => $otpCode,
                'error' => $e->getMessage(),
            ], 500);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'envoi du code OTP. Veuillez réessayer plus tard.',
                'email' => $user->email,
            ], 500);
        }
    }
}

public function login(Request $request)
{
    // Validation des données
    $request->validate([
        'email' => 'required|email',
        'password' => 'required|string',
    ]);

    // Rechercher l'utilisateur par email
    $user = User::where('email', $request->email)->first();

    // Vérifier si l'utilisateur existe et si le mot de passe est correct
    if (!$user || !Hash::check($request->password, $user->password)) {
        Log::warning('Tentative de connexion échouée', [
            'email' => $request->email,
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Email ou mot de passe incorrect.'
        ], 401);
    }

    // Vérifier si l'email est vérifié
    if (!$user->email_verified_at) {
        Log::info('Tentative de connexion avec email non vérifié', [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Veuillez vérifier votre email avant de vous connecter.',
            'email' => $user->email,
            'user_id' => $user->id,
            'needs_verification' => true,
        ], 403);
    }

    try {
        $accessToken = $user->createToken('auth-token')->plainTextToken;
        Log::info('Connexion réussie', [
            'user_id' => $user->id,
            'email' => $user->email,
            'role' => $user->role,
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Connexion réussie.',
            'accessToken' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => 60 * 24 * 30, // 30 jours (en minutes) - modifiable dans sanctum.php
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'email_verified_at' => $user->email_verified_at,
            ]
        ], 200);

    } catch (\Exception $e) {
        Log::error('Erreur lors de la création du token', [
            'user_id' => $user->id,
            'error' => $e->getMessage(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Erreur lors de la connexion. Veuillez réessayer.'
        ], 500);
    }
}

public function logout(Request $request)
{
    $request->user()->tokens()->delete();
    return response()->json([
        'message' => 'Vous avez été déconnecté.'
    ], 200);
}


public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
             Log::info('Demande reset password pour email inexistant', [
            'email' => $request->email,
            'ip' => $request->ip(),
        ]);
        
        return response()->json([
            'success' => true,
            'message' => 'Si cet email existe, vous recevrez un lien de réinitialisation.',
        ], 200);
        }


        // Création d'un token sécurisé
        $token = Str::random(60);

        // Stockage hashé (comme le recommande Laravel)
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            [
                'email'       => $user->email,
                'token'       => Hash::make($token),
                'created_at'  => now(),
            ]
        );

      try {
    Log::info('Tentative d\'envoi email reset password', [
        'to' => $user->email,
        'token' => $token,
    ]);

    Mail::to($user->email)->send(new ResetPasswordLink($token, $user->email));
    Log::info('Email reset password envoyé avec succès', ['email' => $user->email]);
      return response()->json([
            'success' => true,
            'message' => 'Si cet email existe, vous recevrez un lien de réinitialisation.',
        ], 200);
} catch (\Exception $e) {
    Log::error('Échec TOTAL envoi email reset password', [
        'email' => $user->email,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);

    // Pour debug : retourne le message d'erreur en JSON
    return response()->json([
        'success' => false,
        'message' => 'Erreur envoi email',
        'debug_error' => $e->getMessage(),
    ], 500);
}
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'token'                 => 'required|string',
            'email'                 => 'required|email|exists:users,email',
            'password'              => 'required|min:8|confirmed',
            'password_confirmation' => 'required',
        ]);

        $resetRecord = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->first();

        if (!$resetRecord) {
            return response()->json([
                'success' => false,
                'message' => 'Demande de réinitialisation invalide ou expirée.',
            ], 422);
        }

        // Vérification du token hashé
        if (!Hash::check($request->token, $resetRecord->token)) {
            return response()->json([
                'success' => false,
                'message' => 'Token invalide.',
            ], 422);
        }

        // Vérification d'expiration (exemple : 60 minutes)
        if (now()->diffInMinutes($resetRecord->created_at) > 60) {
            DB::table('password_reset_tokens')
                ->where('email', $request->email)
                ->delete();

            return response()->json([
                'success' => false,
                'message' => 'Le lien de réinitialisation a expiré.',
            ], 422);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            // Ne devrait jamais arriver grâce à la validation exists:users,email
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur introuvable.',
            ], 404);
        }

        // Mise à jour du mot de passe
        $user->password = Hash::make($request->password);
        $user->save();

        // Nettoyage immédiat du token (usage unique)
        DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->delete();

        // Optionnel : invalider tous les tokens existants de l'utilisateur (plus sécurisé)
        // $user->tokens()->delete();

        Log::info('Mot de passe réinitialisé avec succès', [
            'user_id' => $user->id,
            'email'   => $user->email,
            'ip'      => $request->ip(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Votre mot de passe a été réinitialisé avec succès. Vous pouvez maintenant vous connecter.',
        ], 200);
    }
}
