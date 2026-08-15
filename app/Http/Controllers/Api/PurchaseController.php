<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CoursePurchase;
use App\Actions\CompleteCoursePurchase;
use Illuminate\Http\Request;
use Stripe\StripeClient;
use Stripe\Exception\ApiErrorException;

class PurchaseController extends Controller
{
    public function show(Request $request, CoursePurchase $purchase)
    {
        $user = $request->user();

        if ($purchase->student_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => "Achat introuvable.",
            ], 404);
        }

        if ($purchase->status === 'pending' && $purchase->stripe_payment_intent_id) {
            $this->reconcileWithStripe($purchase);
            $purchase->refresh();
        }

        return response()->json([
            'id' => $purchase->id,
            'course_id' => $purchase->course_id,
            'status' => $purchase->status,
            'amount_total' => $purchase->amount_total,
            'currency' => $purchase->currency,
            'purchased_at' => $purchase->purchased_at,
        ]);
    }

    /**
     * Filet de sécurité : si le webhook n'est jamais arrivé (endpoint down,
     * event perdu, dev local sans `stripe listen`...), on vérifie
     * directement auprès de Stripe au lieu de laisser l'étudiant bloqué
     * indéfiniment. Throttle à 5s pour ne pas spammer l'API pendant le polling.
     */
    private function reconcileWithStripe(CoursePurchase $purchase): void
    {
        if ($purchase->updated_at && $purchase->updated_at->gt(now()->subSeconds(5))) {
            return;
        }

        $stripe = new StripeClient(config('services.stripe.secret'));

        try {
            $intent = $stripe->paymentIntents->retrieve($purchase->stripe_payment_intent_id);
        } catch (ApiErrorException $e) {
            return;
        }

        if ($intent->status === 'succeeded') {
            app(CompleteCoursePurchase::class)->handle($intent);
        } elseif ($intent->status === 'canceled') {
            $purchase->update(['status' => 'failed']);
        }
    }
}
