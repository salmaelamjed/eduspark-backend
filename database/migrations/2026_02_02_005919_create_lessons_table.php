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
        Schema::create('lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')->constrained()->onDelete('cascade');
            $table->string('title');
            $table->string('slug')->index();                    // not necessarily unique globally
            $table->unsignedInteger('order')->default(1);
            $table->boolean('is_preview')->default(false);      // free preview lesson
            $table->timestamps();
            
            // Optional: unique order per module
            $table->unique(['module_id', 'order']);
            
            // Optional: index for fast slug lookup per course
            // $table->index(['module_id', 'slug']);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lessons');
    }
};
