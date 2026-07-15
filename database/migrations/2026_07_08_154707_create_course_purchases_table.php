<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->onDelete('cascade');
            $table->foreignId('student_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('teacher_id')->constrained('users')->onDelete('cascade');
            $table->string('stripe_payment_intent_id')->unique();
            $table->string('stripe_transfer_id')->nullable();
            $table->decimal('amount_total', 10, 2);
            $table->decimal('commission_amount', 10, 2);
            $table->decimal('teacher_amount', 10, 2);
            $table->string('currency', 3)->default('EUR');
            $table->string('status')->default('pending'); // pending, completed, refunded, failed
            $table->timestamp('purchased_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_purchases');
    }
};
