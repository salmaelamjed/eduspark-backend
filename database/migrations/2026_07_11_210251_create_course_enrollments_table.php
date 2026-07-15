<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->onDelete('cascade');
            $table->foreignId('student_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('purchase_id')->nullable()->constrained('course_purchases')->onDelete('set null');
            $table->timestamp('enrolled_at');
            $table->timestamps();

            $table->unique(['course_id', 'student_id']); // un seul enrollment par (cours, étudiant)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_enrollments');
    }
};
