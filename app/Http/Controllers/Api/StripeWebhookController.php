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
use App\Actions\CompleteCoursePurchase;


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
        app(CompleteCoursePurchase::class)->handle($paymentIntent);
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
