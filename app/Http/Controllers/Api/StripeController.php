<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Stripe\StripeClient;
use Stripe\Exception\ApiErrorException;
use Illuminate\Support\Facades\Log;

class StripeController extends Controller
{
    protected $stripe;

    public function __construct()
    {
        $this->stripe = new StripeClient(config('services.stripe.secret'));
    }

    /**
     * Créer un compte Stripe Connect Express (API v2 - CORRECTED)
     */
    public function createConnectAccount(Request $request)
    {
        $user = $request->user();

        if (!$user->isTeacher()) {
            return response()->json([
                'success' => false,
                'message' => 'Seuls les enseignants peuvent créer un compte Stripe.'
            ], 403);
        }

        if ($user->stripe_account_id) {
            return response()->json([
                'success' => true,
                'message' => 'Compte Stripe déjà existant.',
                'account_id' => $user->stripe_account_id,
                'onboarding_completed' => $user->stripe_onboarding_completed
            ]);
        }

        try {
            // ✅ Création du compte
            $account = $this->stripe->v2->core->accounts->create([
                'contact_email' => $user->email,
                'display_name' => $user->name,
                'identity' => [
                    'country' => 'FR',
                    'entity_type' => 'individual',
                ],
                'configuration' => [
                    'merchant' => [
                        'capabilities' => [
                            'card_payments' => ['requested' => true],
                        ]
                    ]
                ],
                'defaults' => [
                    'responsibilities' => [
                        'losses_collector' => 'application',
                        'fees_collector' => 'application_express',
                    ]
                ],
                'dashboard' => 'express',
                'metadata' => [
                    'user_id' => (string)$user->id,
                    'platform' => 'eduspark'
                ]
            ]);

            // Sauvegarde du compte
            $user->update([
                'stripe_account_id' => $account->id,
                'stripe_account_created_at' => now(),
                'stripe_onboarding_completed' => false,
            ]);

            // ✅ CORRECTION: Génération correcte du lien d'onboarding
            // Il faut utiliser l'API v1 pour les account links car l'API v2 est encore en beta
            $accountLink = $this->stripe->accountLinks->create([
                'account' => $account->id,
                'refresh_url' => route('stripe.refresh'),
                'return_url' => route('stripe.success'),
                'type' => 'account_onboarding',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Compte Stripe créé avec succès.',
                'onboarding_url' => $accountLink->url, // ✅ URL correcte
                'account_id' => $account->id,
                'account_type' => 'merchant'
            ]);

        } catch (ApiErrorException $e) {
            Log::error('Stripe account creation error:', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'code' => $e->getError()->code ?? null
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création du compte Stripe.',
                'error' => $e->getMessage(),
                'code' => $e->getError()->code ?? null
            ], 500);
        }
    }

    /**
     * Rafraîchir le lien d'onboarding
     */
    public function refreshOnboarding(Request $request)
    {
        $user = $request->user();

        if (!$user->stripe_account_id) {
            return response()->json([
                'success' => false,
                'message' => 'Aucun compte Stripe trouvé'
            ], 400);
        }

        try {
            // ✅ Utilisation de l'API v1 pour les account links
            $accountLink = $this->stripe->accountLinks->create([
                'account' => $user->stripe_account_id,
                'refresh_url' => route('stripe.refresh'),
                'return_url' => route('stripe.success'),
                'type' => 'account_onboarding',
            ]);

            return response()->json([
                'success' => true,
                'onboarding_url' => $accountLink->url
            ]);

        } catch (ApiErrorException $e) {
            Log::error('Stripe refresh onboarding error:', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du rafraîchissement: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Vérifier le statut du compte Stripe
     */
    public function getAccountStatus(Request $request)
    {
        $user = $request->user();

        if (!$user->stripe_account_id) {
            return response()->json([
                'success' => true,
                'has_account' => false
            ]);
        }

        try {
            // ✅ Utilisation de l'API v1 pour récupérer les détails du compte
            $account = $this->stripe->accounts->retrieve($user->stripe_account_id);

            // Vérifier si le compte est complet
            $isComplete = false;
            
            if ($account->charges_enabled && $account->payouts_enabled) {
                $isComplete = true;
            }

            // Mettre à jour le statut
            if ($isComplete !== (bool)$user->stripe_onboarding_completed) {
                $user->update([
                    'stripe_onboarding_completed' => $isComplete,
                    'stripe_account_updated_at' => now()
                ]);
            }

            return response()->json([
                'success' => true,
                'has_account' => true,
                'account_id' => $user->stripe_account_id,
                'onboarding_completed' => $isComplete,
                'status' => $isComplete ? 'active' : 'pending',
                'charges_enabled' => $account->charges_enabled ?? false,
                'payouts_enabled' => $account->payouts_enabled ?? false,
                'requirements' => $account->requirements ?? null,
            ]);

        } catch (ApiErrorException $e) {
            Log::error('Stripe account status error:', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Impossible de récupérer le statut du compte.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Callback de succès (redirection frontend)
     */
    public function onboardingSuccess(Request $request)
    {
        // Récupérer l'account_id depuis la requête
        $accountId = $request->query('account_id');
        
        if ($accountId) {
            // Mettre à jour le statut du compte
            $user = $request->user();
            if ($user && $user->stripe_account_id === $accountId) {
                $user->update([
                    'stripe_onboarding_completed' => true,
                    'stripe_account_updated_at' => now()
                ]);
            }
        }

        $redirectUrl = config('app.frontend_url') . '/teacher/dashboard?stripe=onboarding-complete';
        return redirect($redirectUrl);
    }

    /**
     * Callback de refresh
     */
    public function onboardingRefresh(Request $request)
    {
        $redirectUrl = config('app.frontend_url') . '/teacher/dashboard?stripe=onboarding-refresh';
        return redirect($redirectUrl);
    }

    /**
     * Fonction de débogage pour voir les détails du compte
     */
    public function debugAccount(Request $request)
    {
        $user = $request->user();

        if (!$user->stripe_account_id) {
            return response()->json([
                'success' => false,
                'message' => 'Aucun compte Stripe trouvé'
            ], 400);
        }

        try {
            $account = $this->stripe->accounts->retrieve($user->stripe_account_id);

            return response()->json([
                'success' => true,
                'account' => $account
            ]);

        } catch (ApiErrorException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Créer un lien de connexion pour le dashboard Stripe
     */
    public function createLoginLink(Request $request)
    {
        $user = $request->user();

        if (!$user->stripe_account_id) {
            return response()->json([
                'success' => false,
                'message' => 'Aucun compte Stripe trouvé'
            ], 400);
        }

        try {
            $loginLink = $this->stripe->accounts->createLoginLink($user->stripe_account_id);

            return response()->json([
                'success' => true,
                'login_url' => $loginLink->url
            ]);

        } catch (ApiErrorException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création du lien de connexion: ' . $e->getMessage()
            ], 500);
        }
    }
}