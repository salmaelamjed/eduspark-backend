<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
   public function up()
{
    Schema::table('users', function (Blueprint $table) {
        $table->string('stripe_account_id')->nullable()->unique();
        $table->boolean('stripe_onboarding_completed')->default(false);
        $table->timestamp('stripe_account_created_at')->nullable();
        $table->timestamp('stripe_account_updated_at')->nullable();
        $table->decimal('total_earnings', 10, 2)->default(0);
        $table->decimal('total_commission_paid', 10, 2)->default(0);
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
         Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'stripe_account_id',
                'stripe_onboarding_completed',
                'stripe_account_created_at',
                'stripe_account_updated_at',
                'total_earnings',
                'total_commission_paid'
            ]);
        });
    }
};
