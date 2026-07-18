<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('teacher_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('mode', 20)->default('ai');
            $table->string('status', 20)->default('active');

            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            // Une seule room active par étudiant/cours à la fois
            $table->unique(['student_id', 'course_id', 'status'], 'uniq_active_room_per_student_course');
            $table->index(['teacher_id', 'mode', 'status']);
            $table->index('last_message_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_rooms');
    }
};