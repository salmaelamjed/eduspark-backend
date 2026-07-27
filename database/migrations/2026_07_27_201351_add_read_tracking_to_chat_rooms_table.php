<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_rooms', function (Blueprint $table) {
            $table->timestamp('student_last_read_at')->nullable()->after('teacher_id');
            $table->timestamp('teacher_last_read_at')->nullable()->after('student_last_read_at');
        });
    }

    public function down(): void
    {
        Schema::table('chat_rooms', function (Blueprint $table) {
            $table->dropColumn(['student_last_read_at', 'teacher_last_read_at']);
        });
    }
};
