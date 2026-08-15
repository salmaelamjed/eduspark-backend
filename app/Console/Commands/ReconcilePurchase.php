<?php

namespace App\Console\Commands;

use App\Models\CoursePurchase;
use App\Actions\CompleteCoursePurchase;
use Illuminate\Console\Command;
use Stripe\StripeClient;

class ReconcilePurchase extends Command
{
    protected $signature = 'purchase:reconcile {purchase_id}';
    protected $description = 'Vérifie un achat directement auprès de Stripe et complète si succeeded';

    public function handle(): int
    {
        $purchase = CoursePurchase::findOrFail($this->argument('purchase_id'));

        if (!$purchase->stripe_payment_intent_id) {
            $this->error('Pas de PaymentIntent sur cet achat.');
            return self::FAILURE;
        }

        $stripe = new StripeClient(config('services.stripe.secret'));
        $intent = $stripe->paymentIntents->retrieve($purchase->stripe_payment_intent_id);

        $this->info("Stripe status: {$intent->status}");

        if ($intent->status === 'succeeded') {
            app(CompleteCoursePurchase::class)->handle($intent);
            $this->info('Achat complété.');
        } else {
            $this->warn('Pas succeeded côté Stripe, rien fait.');
        }

        return self::SUCCESS;
    }
}
