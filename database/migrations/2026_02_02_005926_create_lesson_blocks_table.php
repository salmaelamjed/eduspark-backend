<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained()->onDelete('cascade');

            // Type principal du bloc
            $table->enum('type', [
                'heading',      // titres structurants (h1 à h6)
                'paragraph',    // texte standard / paragraphe
                'list',         // liste ordonnée ou non ordonnée
                'quote',        // citation / blockquote
                'image',
                'video',
                'audio',
                'file',         // pdf, zip, doc...
                'code',         // bloc de code avec coloration
                'quiz',         // question / exercice interactif
                'embed',        // iframe (youtube, figma, tweet, etc.)
                'divider',      // séparation visuelle (hr-like)
                'callout',      // encart d'information (note, tip, warning...)
            ])->default('paragraph');

            // Contenu principal et médias
            $table->longText('content')->nullable();         // Markdown, HTML ou texte brut
            $table->string('media_url')->nullable();         // URL vidéo, image, pdf, embed...

            // Configurations flexibles (Sous-types : h1/h2, listes ordered/unordered, etc.)
            $table->json('settings')->nullable();            // { "level": "h2" } ou { "style": "ordered" }

            // Structures complexes
            $table->json('quiz_data')->nullable();           // Structure complète du quiz (Questions, options, réponses)
            $table->json('code_data')->nullable();           // { "language": "php", "code": "..." }

            // Métadonnées optionnelles classiques
            $table->integer('duration_seconds')->nullable(); // vidéos / audio
            $table->string('language', 2)->default('fr');
            // Positionnement et visibilité
            $table->unsignedInteger('order')->default(1);
            $table->boolean('is_preview')->default(false);    // visible sans inscription ?
            $table->boolean('is_hidden')->default(false);     // caché temporairement ?

            $table->timestamps();

            // Unicité par leçon + ordre
            $table->unique(['lesson_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_blocks');
    }
};
