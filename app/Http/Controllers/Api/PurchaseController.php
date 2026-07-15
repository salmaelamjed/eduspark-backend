<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CoursePurchase;
use Illuminate\Http\Request;

class PurchaseController extends Controller
{
    /**
     * Retourne le statut réel d'un achat, mis à jour par le webhook Stripe.
     * Utilisé par le frontend pour du polling après confirmation du paiement.
     */
    public function show(Request $request, CoursePurchase $purchase)
    {
        $user = $request->user();

        // Un student ne doit voir que ses propres achats — jamais ceux
        // d'un autre utilisateur, même en devinant l'id dans l'URL.
        if ($purchase->student_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => "Achat introuvable.",
            ], 404);
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
}
