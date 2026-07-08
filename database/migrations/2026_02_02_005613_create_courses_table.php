<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
       Schema::create('courses', function (Blueprint $table) {
    $table->id();
    $table->string('title');
    $table->string('slug')->unique();
    $table->text('description')->nullable();
    $table->string('thumbnail')->nullable();           // path or URL
    $table->enum('level', ['beginner', 'intermediate', 'advanced'])->default('beginner');
    $table->string('language')->default('English');
    $table->decimal('price', 8, 2)->default(0.00);
    $table->boolean('is_free')->default(false);

    $table->foreignId('domain_id')->constrained()->onDelete('cascade');
    $table->foreignId('teacher_id')->constrained('users')->onDelete('cascade');

    $table->enum('status', ['draft', 'published', 'archived'])->default('draft');

    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};
