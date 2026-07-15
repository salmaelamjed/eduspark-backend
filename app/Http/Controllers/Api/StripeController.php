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
     * Créer un compte Stripe Connect Express (API v2)
     */
    public function createConnectAccount(Request $request)
    {
        $user = $request->user();

        if (!$user->isTeacher()) {
            return response()->json([
                'success' => false,
                'message' => 'Seuls les enseignants peuvent créer un compte de paiement Stripe.'
            ], 403);
        }

       if ($user->stripe_account_id) {
            return response()->json([
                'success' => true,
                'message' => 'Vous avez déjà un compte Stripe actif.',
                'account_id' => $user->stripe_account_id,
                'onboarding_completed' => $user->stripe_onboarding_completed
            ]);
        }

    //     $request->validate([
    //     'country' => 'required|string|size:2', // code ISO, ex: FR, BE, MA...
    // ]);

    // $country = strtoupper($request->input('country'));
    // //  whitelister les pays supportés par Stripe Connect
    // if (!in_array($country, config('services.stripe.supported_countries', ['FR']))) {
    //     return response()->json([
    //         'success' => false,
    //         'message' => "Ce pays n'est pas encore supporté pour les paiements."
    //     ], 422);
    // }

        try {
            $account = $this->stripe->v2->core->accounts->create([
                'contact_email' => $user->email,
                'display_name' => $user->name,
                'identity' => [
                    // 'country' => $country,
                    'country' => 'FR',
                    'entity_type' => 'individual',
                ],
                'configuration' => [
                    'merchant' => [
                        'capabilities' => [
                            'card_payments' => ['requested' => true],
                        ]
                    ],
                    'recipient' => [
                    'capabilities' => [
                        'stripe_balance' => [
                            'stripe_transfers' => ['requested' => true],
                        ],
                    ],
                ],
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

            $user->update([
                'stripe_account_id' => $account->id,
                // 'country' => $country,
                'stripe_account_created_at' => now(),
                'stripe_onboarding_completed' => false,
            ]);

            $accountLink = $this->stripe->accountLinks->create([
                'account' => $account->id,
                'refresh_url' => route('stripe.refresh'),
                'return_url' => route('stripe.success'),
                'type' => 'account_onboarding',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Votre compte Stripe a été créé avec succès. Veuillez compléter le processus d\'inscription.',
                'onboarding_url' => $accountLink->url,
                'account_id' => $account->id,
                'account_type' => 'merchant'
            ]);

        } catch (ApiErrorException $e) {
               $errorCode = $e->getError()->code ?? null;
            $userMessage = $this->getUserFriendlyMessage($errorCode, $e->getMessage());
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
     * Vérifier le statut du compte Stripe (API v2)
     */
    public function getAccountStatus(Request $request)
    {
        $user = $request->user();

        if (!$user->stripe_account_id) {
            return response()->json([
                'success' => true,
                'has_account' => false,
                'message' => 'Vous n\'avez pas encore de compte Stripe.'
            ]);
        }


        try {
            $account = $this->stripe->v2->core->accounts->retrieve(
                $user->stripe_account_id,
                ['include' => ['configuration.merchant', 'identity', 'requirements', 'defaults']]
            );

            $isComplete = false;
            $chargesEnabled = false;
            $payoutsEnabled = false;

            if (isset($account->configuration->merchant->capabilities)) {
                $capabilities = $account->configuration->merchant->capabilities;

                if (isset($capabilities->card_payments)) {
                    $chargesEnabled = $capabilities->card_payments->status === 'active';
                    $payoutsEnabled = $capabilities->card_payments->status === 'active';
                }
            }

            $requirements = $account->requirements ?? null;
            $requirementsComplete = true;

            if ($requirements && isset($requirements->entries) && !empty($requirements->entries)) {
                $requirementsComplete = false;
            }

            // Le compte est complet si les paiements sont activés ET que les prérequis sont remplis
            $isComplete = $chargesEnabled && $payoutsEnabled && $requirementsComplete;

            // Mettre à jour le statut dans la base de données
            if ($isComplete !== (bool)$user->stripe_onboarding_completed) {
                $user->update([
                    'stripe_onboarding_completed' => $isComplete,
                    'stripe_account_updated_at' => now()
                ]);
            }

            $statusMessage = $isComplete
                ? 'Votre compte Stripe est actif et prêt à recevoir des paiements.'
                : 'Votre compte Stripe est en cours de configuration. Veuillez finaliser votre inscription.';

             return response()->json([
                'success' => true,
                'has_account' => true,
                'account_id' => $user->stripe_account_id,
                'onboarding_completed' => $isComplete,
                'status' => $isComplete ? 'active' : 'pending',
                'charges_enabled' => $chargesEnabled,
                'payouts_enabled' => $payoutsEnabled,
                'message' => $statusMessage,
            ]);

        } catch (ApiErrorException $e) {
             $errorCode = $e->getError()->code ?? null;
            $userMessage = $this->getUserFriendlyMessage($errorCode, $e->getMessage());
            Log::error('Stripe account status error:', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

             return response()->json([
                'success' => false,
                'message' => 'Nous rencontrons un problème technique. Veuillez réessayer plus tard.',
                'code' => $errorCode
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
                'message' => 'Vous n\'avez pas encore de compte Stripe.'
            ], 400);
        }

        try {
            $accountLink = $this->stripe->accountLinks->create([
                'account' => $user->stripe_account_id,
                'refresh_url' => route('stripe.refresh'),
                'return_url' => route('stripe.success'),
                'type' => 'account_onboarding',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Vous avez été redirigé pour finaliser votre inscription Stripe.',
                'onboarding_url' => $accountLink->url
            ]);

        } catch (ApiErrorException $e) {
              $errorCode = $e->getError()->code ?? null;
            $userMessage = $this->getUserFriendlyMessage($errorCode, $e->getMessage());
            Log::error('Stripe refresh onboarding error:', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

             return response()->json([
                'success' => false,
                'message' => $userMessage,
                'code' => $errorCode
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
            $user = $request->user();
            if ($user && $user->stripe_account_id === $accountId) {
                $user->update([
                    'stripe_onboarding_completed' => true,
                    'stripe_account_updated_at' => now()
                ]);
            }
        }

        $redirectUrl = config('app.frontend_url') . '/dashboard/integrations?stripe=onboarding-complete';
        return redirect($redirectUrl);
    }

    /**
     * Callback de refresh
     */
    public function onboardingRefresh(Request $request)
    {
        $redirectUrl = config('app.frontend_url') . '/dashboard/integrations?stripe=onboarding-refresh';
        return redirect($redirectUrl);
    }

     /**
     * Convertir les erreurs Stripe en messages compréhensibles pour l'utilisateur
     */
    private function getUserFriendlyMessage(?string $errorCode, string $originalMessage): string
    {
        $messages = [
            'invalid_email' => 'Votre email n\'est pas valide. Veuillez vérifier votre adresse email.',
            'invalid_phone' => 'Votre numéro de téléphone n\'est pas valide.',
            'invalid_country' => 'Le pays sélectionné n\'est pas supporté.',
            'invalid_tax_id' => 'Votre numéro d\'identification fiscale n\'est pas valide.',
            'invalid_birthday' => 'Votre date de naissance n\'est pas valide.',
            'invalid_address' => 'Votre adresse n\'est pas valide. Veuillez vérifier les informations saisies.',
            'invalid_dob' => 'Votre date de naissance doit correspondre au format requis par Stripe.',
            'invalid_ssn' => 'Le numéro de sécurité sociale fourni n\'est pas valide.',
            'invalid_identity' => 'Vos informations d\'identité ne sont pas valides.',
            'invalid_account' => 'Les informations du compte bancaire fourni ne sont pas valides.',
            'invalid_routing_number' => 'Le code de routage bancaire n\'est pas valide pour ce pays.',
            'invalid_bank_account' => 'Le numéro de compte bancaire fourni n\'est pas valide.',
            'invalid_currency' => 'La devise sélectionnée n\'est pas supportée.',
            'invalid_iban' => 'Votre IBAN n\'est pas valide.',
            'invalid_swift' => 'Le code SWIFT/BIC fourni n\'est pas valide.',
            'invalid_bic' => 'Le code BIC fourni n\'est pas valide.',
            'invalid_document' => 'Le document d\'identité fourni n\'est pas valide.',
            'invalid_document_expired' => 'Votre document d\'identité est expiré.',
            'invalid_verification' => 'La vérification d\'identité a échoué.',

            'missing_document' => 'Un document d\'identité est requis. Veuillez en fournir un.',
            'missing_account' => 'Un compte bancaire est requis pour recevoir des paiements.',
            'missing_address' => 'Votre adresse est requise.',
            'missing_dob' => 'Votre date de naissance est requise.',
            'missing_phone' => 'Votre numéro de téléphone est requis.',
            'missing_tax_id' => 'Votre numéro d\'identification fiscale est requis.',

            'unverified_identity' => 'Votre identité n\'a pas pu être vérifiée. Veuillez contacter le support.',
            'unverified_bank' => 'Votre compte bancaire n\'a pas pu être vérifié.',
            'unverified_business' => 'Votre entreprise n\'a pas pu être vérifiée.',

            'account_exists' => 'Un compte Stripe existe déjà pour cet email.',
            'account_already_verified' => 'Votre compte est déjà vérifié.',
            'account_not_verified' => 'Votre compte n\'est pas encore vérifié.',

            'rate_limit' => 'Trop de tentatives. Veuillez attendre quelques minutes avant de réessayer.',
            'server_error' => 'Une erreur technique s\'est produite. Notre équipe a été notifiée.',
            'connection_error' => 'Problème de connexion avec Stripe. Veuillez réessayer.',
            'timeout' => 'La demande a expiré. Veuillez réessayer.',

            'permission_denied' => 'Vous n\'avez pas les autorisations nécessaires.',
            'not_authorized' => 'Vous n\'êtes pas autorisé à effectuer cette action.',
        ];

        // Si on a un message personnalisé pour ce code d'erreur
        if ($errorCode && isset($messages[$errorCode])) {
            return $messages[$errorCode];
        }

        // Messages génériques selon le type d'erreur
        if (strpos($originalMessage, 'email') !== false) {
            return 'Veuillez vérifier votre adresse email.';
        }
        if (strpos($originalMessage, 'phone') !== false) {
            return 'Veuillez vérifier votre numéro de téléphone.';
        }
        if (strpos($originalMessage, 'address') !== false) {
            return 'Veuillez vérifier votre adresse.';
        }
        if (strpos($originalMessage, 'bank') !== false) {
            return 'Veuillez vérifier vos informations bancaires.';
        }
        if (strpos($originalMessage, 'document') !== false) {
            return 'Un problème a été détecté avec votre document d\'identité.';
        }
        if (strpos($originalMessage, 'verification') !== false) {
            return 'La vérification de votre identité a échoué.';
        }
        if (strpos($originalMessage, 'timeout') !== false) {
            return 'La connexion avec Stripe a expiré. Veuillez réessayer.';
        }

        // Message par défaut pour les autres erreurs
        return 'Nous rencontrons un problème avec Stripe. Veuillez réessayer ou contacter le support.';
    }



}

