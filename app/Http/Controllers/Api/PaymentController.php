<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CoursePurchase;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;
use Stripe\Exception\ApiErrorException;
use Throwable;

class PaymentController extends Controller
{
    protected StripeClient $stripe;

    private const REUSABLE_STATUSES = [
        'requires_payment_method',
        'requires_confirmation',
        'requires_action',
    ];

    private const TERMINAL_OR_PROCESSING_STATUSES = [
        'succeeded',
        'processing',
    ];

    public function __construct()
    {
        $this->stripe = new StripeClient(config('services.stripe.secret'));
    }

    public function createPaymentIntent(Request $request, Course $course): JsonResponse
    {
        $student = $request->user();

        $validationError = $this->validatePurchasability($course, $student);
        if ($validationError) {
            return $validationError;
        }

        $lock = Cache::lock("checkout:course:{$course->id}:student:{$student->id}", 15);

        try {
            return $lock->block(5, fn () => $this->handleIntentCreation($course, $student));
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            Log::warning('Verrou checkout non obtenu', [
                'course_id' => $course->id,
                'student_id' => $student->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Une opération de paiement est déjà en cours pour ce cours.',
            ], 409);
        }
    }

    private function validatePurchasability(Course $course, User $student): ?JsonResponse
    {
        if ($course->status !== 'published') {
            return response()->json(['success' => false, 'message' => "Ce cours n'est pas disponible à l'achat."], 422);
        }
        if ($course->is_free || $course->price <= 0) {
            return response()->json(['success' => false, 'message' => "Ce cours est gratuit, aucun paiement n'est nécessaire."], 422);
        }
        if ($course->teacher_id === $student->id) {
            return response()->json(['success' => false, 'message' => "Vous ne pouvez pas acheter votre propre cours."], 422);
        }
        $teacher = $course->teacher;
        if (!$teacher->stripe_account_id || !$teacher->stripe_onboarding_completed) {
            return response()->json(['success' => false, 'message' => "Ce cours n'est pas encore disponible à l'achat."], 422);
        }
        return null;
    }

    private function handleIntentCreation(Course $course, User $student): JsonResponse
    {
        $existing = CoursePurchase::where('course_id', $course->id)
            ->where('student_id', $student->id)
            ->whereIn('status', ['pending', 'completed'])
            ->latest()
            ->first();

        if ($existing && $existing->status === 'completed') {
            return response()->json(['success' => false, 'message' => 'Vous avez déjà acheté ce cours.'], 422);
        }

        if ($existing && $existing->stripe_payment_intent_id) {
            $reused = $this->tryReuseExistingIntent($existing);
            if ($reused) {
                return $reused;
            }
        }

        // === RÉSERVATION AVANT APPEL STRIPE ===
        // La ligne existe TOUJOURS avant qu'on parle à Stripe. Ainsi, même si
        // la DB tombe juste après l'appel Stripe, on a un id de purchase
        // stable à utiliser comme clé d'idempotence — plus jamais de
        // PaymentIntent orphelin créé sans ligne correspondante.
        $purchase = $existing ?? CoursePurchase::create([
            'course_id' => $course->id,
            'student_id' => $student->id,
            'teacher_id' => $course->teacher_id,
            'stripe_payment_intent_id' => null,
            'amount_total' => $course->price,
            'commission_amount' => 0,
            'teacher_amount' => 0,
            'currency' => $course->currency ?? 'EUR',
            'status' => 'pending',
        ]);

        return $this->createNewIntent($course, $student, $purchase);
    }

    private function tryReuseExistingIntent(CoursePurchase $existing): ?JsonResponse
    {
        try {
            $intent = $this->stripe->paymentIntents->retrieve($existing->stripe_payment_intent_id);
        } catch (ApiErrorException $e) {
            Log::warning('PaymentIntent introuvable côté Stripe, recréation', [
                'purchase_id' => $existing->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        if (in_array($intent->status, self::TERMINAL_OR_PROCESSING_STATUSES, true)) {
            return response()->json([
                'success' => true,
                'already_processed' => true,
                'stripe_status' => $intent->status,
                'purchase_id' => $existing->id,
            ]);
        }

        if (in_array($intent->status, self::REUSABLE_STATUSES, true)) {
            return response()->json([
                'success' => true,
                'client_secret' => $intent->client_secret,
                'purchase_id' => $existing->id,
            ]);
        }

        // canceled / expired / requires_capture inattendu → on laisse
        // createNewIntent réutiliser la MÊME ligne purchase (pas de doublon).
        return null;
    }

    private function createNewIntent(Course $course, User $student, CoursePurchase $purchase): JsonResponse
    {
        $teacher = $course->teacher;

        $amountTotalCents = (int) round($course->price * 100);
        $commissionRate = (float) config('services.stripe.commission_rate', 10);
        $commissionCents = (int) round($amountTotalCents * $commissionRate / 100);
        $teacherCents = $amountTotalCents - $commissionCents;

        try {
            $paymentIntent = $this->stripe->paymentIntents->create([
                'amount' => $amountTotalCents,
                'currency' => strtolower($course->currency ?? 'eur'),
                'automatic_payment_methods' => ['enabled' => true],
                'transfer_data' => ['destination' => $teacher->stripe_account_id],
                'application_fee_amount' => $commissionCents,
                'metadata' => [
                    'course_id' => (string) $course->id,
                    'student_id' => (string) $student->id,
                    'teacher_id' => (string) $teacher->id,
                    'purchase_id' => (string) $purchase->id,
                    'platform' => 'eduspark',
                ],
            ], [
                // Clé stable sur l'id de la ligne réservée : un retry sur la
                // MÊME ligne renvoie le MÊME intent, sans jamais en dupliquer
                // un nouveau pour une ligne différente.
                'idempotency_key' => "purchase-{$purchase->id}-create",
            ]);
        } catch (ApiErrorException $e) {
            $purchase->update(['status' => 'failed']);
            Log::error('Erreur création PaymentIntent', [
                'purchase_id' => $purchase->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Impossible de démarrer le paiement. Veuillez réessayer.',
            ], 500);
        }

        try {
            $purchase->update([
                'stripe_payment_intent_id' => $paymentIntent->id,
                'commission_amount' => $commissionCents / 100,
                'teacher_amount' => $teacherCents / 100,
                'status' => 'pending',
            ]);
        } catch (Throwable $e) {
            // Compensation : le PaymentIntent existe côté Stripe mais on n'a
            // pas pu l'attacher à notre ligne (DB down, etc.). On l'annule
            // pour ne jamais laisser un intent orphelin utilisable.
            try {
                $this->stripe->paymentIntents->cancel($paymentIntent->id);
            } catch (ApiErrorException $cancelError) {
                Log::critical('Échec annulation PaymentIntent orphelin', [
                    'payment_intent_id' => $paymentIntent->id,
                    'error' => $cancelError->getMessage(),
                ]);
            }

            Log::error('Échec mise à jour purchase après création Stripe', [
                'purchase_id' => $purchase->id,
                'payment_intent_id' => $paymentIntent->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Impossible de démarrer le paiement. Veuillez réessayer.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'client_secret' => $paymentIntent->client_secret,
            'purchase_id' => $purchase->id,
        ]);
    }
}
