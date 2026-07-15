<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CoursePurchase;
use App\Models\CourseEnrollment;
use App\Models\Commission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Stripe\Webhook;
use Stripe\Exception\SignatureVerificationException;
use UnexpectedValueException;

class StripeWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $webhookSecret = config('services.stripe.webhook_secret');

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
        } catch (UnexpectedValueException $e) {
            Log::warning('Webhook Stripe: payload invalide', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Invalid payload'], 400);
        } catch (SignatureVerificationException $e) {
            Log::warning('Webhook Stripe: signature invalide', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        switch ($event->type) {
            case 'payment_intent.succeeded':
                $this->handlePaymentSucceeded($event->data->object);
                break;

            case 'payment_intent.payment_failed':
                $this->handlePaymentFailed($event->data->object);
                break;

            default:
                // Événements non gérés — on accuse réception sans traiter.
                break;
        }

        return response()->json(['received' => true]);
    }

    private function handlePaymentSucceeded($paymentIntent): void
    {
        DB::transaction(function () use ($paymentIntent) {
            // lockForUpdate : si Stripe renvoie le même événement deux fois
            // (retry réseau), on évite un traitement concurrent en double.
            $purchase = CoursePurchase::where('stripe_payment_intent_id', $paymentIntent->id)
                ->lockForUpdate()
                ->first();

            if (!$purchase) {
                Log::warning('Webhook: purchase introuvable pour ce PaymentIntent', [
                    'payment_intent_id' => $paymentIntent->id,
                ]);
                return;
            }

            // Idempotence : déjà traité, on ne refait rien (ni double
            // enrollment, ni double ligne de commission).
            if ($purchase->status === 'completed') {
                return;
            }

            $purchase->update([
                'status' => 'completed',
                'purchased_at' => now(),
            ]);

            CourseEnrollment::firstOrCreate(
                [
                    'course_id' => $purchase->course_id,
                    'student_id' => $purchase->student_id,
                ],
                [
                    'purchase_id' => $purchase->id,
                    'enrolled_at' => now(),
                ]
            );

            Commission::create([
                'purchase_id' => $purchase->id,
                'teacher_id' => $purchase->teacher_id,
                'course_id' => $purchase->course_id,
                'amount' => $purchase->commission_amount,
                'status' => 'paid', // le transfert Stripe est déjà fait via application_fee_amount
                'paid_at' => now(),
            ]);

            $teacher = $purchase->teacher;
            $teacher->increment('total_earnings', $purchase->teacher_amount);
            $teacher->increment('total_commission_paid', $purchase->commission_amount);

            Log::info('Achat complété via webhook', [
                'purchase_id' => $purchase->id,
                'course_id' => $purchase->course_id,
                'student_id' => $purchase->student_id,
            ]);
        });
    }

    private function handlePaymentFailed($paymentIntent): void
    {
        $purchase = CoursePurchase::where('stripe_payment_intent_id', $paymentIntent->id)->first();

        if (!$purchase || $purchase->status === 'completed') {
            return;
        }

        $purchase->update(['status' => 'failed']);

        Log::info('Paiement échoué via webhook', [
            'purchase_id' => $purchase->id,
            'payment_intent_id' => $paymentIntent->id,
        ]);
    }
}
