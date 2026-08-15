<?php

namespace App\Actions;

use App\Models\CoursePurchase;
use App\Models\CourseEnrollment;
use App\Models\Commission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CompleteCoursePurchase
{
    /**
     * Marque un achat comme complété à partir d'un PaymentIntent Stripe
     * confirmé "succeeded". Idempotent : appelable depuis le webhook OU
     * depuis une reconciliation manuelle sans jamais dupliquer
     * enrollment/commission.
     */
    public function handle(object $paymentIntent): void
    {
        DB::transaction(function () use ($paymentIntent) {
            $purchase = CoursePurchase::where('stripe_payment_intent_id', $paymentIntent->id)
                ->lockForUpdate()
                ->first();

            if (!$purchase) {
                Log::warning('Reconciliation: purchase introuvable pour ce PaymentIntent', [
                    'payment_intent_id' => $paymentIntent->id,
                ]);
                return;
            }

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
                'status' => 'paid',
                'paid_at' => now(),
            ]);

            $teacher = $purchase->teacher;
            $teacher->increment('total_earnings', $purchase->teacher_amount);
            $teacher->increment('total_commission_paid', $purchase->commission_amount);

            Log::info('Achat complété', [
                'purchase_id' => $purchase->id,
                'course_id' => $purchase->course_id,
                'student_id' => $purchase->student_id,
            ]);
        });
    }
}
