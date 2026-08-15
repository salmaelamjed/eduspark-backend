<?php

namespace App\Services\AI;

use App\Models\Course;
use App\Models\Lesson;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class GroqAIService
{
   public function __construct(
    private readonly string $apiKey = '',
    private readonly string $apiUrl = '',
    private readonly string $model = '',
) {}

public static function fromConfig(): self
{
    return new self(
        apiKey: config('services.groq.api_key'),
        apiUrl: config('services.groq.api_url'),
        model: config('services.groq.model'),
    );
}

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @return array{content: string, usage: array|null}
     *
     * @throws RuntimeException
     */
    public function chat(array $messages, array $options = []): array
    {
        $response = Http::withToken($this->apiKey)
            ->timeout(30)
            ->retry(2, 300, throw: false)
            ->post($this->apiUrl, [
                'model' => $this->model,
                'messages' => $messages,
                'temperature' => $options['temperature'] ?? 0.6,
                'max_tokens' => $options['max_tokens'] ?? 1024,
                'top_p' => $options['top_p'] ?? 1,
                'stream' => false,
            ]);

        if ($response->failed()) {
            Log::error('Groq API request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new RuntimeException('Groq API request failed.');
        }

        $data = $response->json();

        return [
            'content' => $data['choices'][0]['message']['content'] ?? '',
            'usage' => $data['usage'] ?? null,
        ];
    }

public function buildSystemPrompt(?string $courseContext = null): string
{
    $base = <<<PROMPT
    Tu es l'assistant pédagogique IA d'EduSpark, une plateforme e-learning.

    RÈGLE ABSOLUE — PRIORITÉ MAXIMALE :
    Réponds TOUJOURS et UNIQUEMENT à la question précise que l'étudiant vient de poser.
    Ne te réintroduis jamais, ne redécris jamais le plan du cours, ne redis jamais
    "Bonjour, nous allons commencer le cours..." après le tout premier message de la
    conversation. Chaque réponse doit apporter une information nouvelle et utile —
    jamais répéter ce que tu as déjà dit dans un message précédent.

    TON ET POSTURE :
    - Sois respectueux, chaleureux et professionnel, comme un excellent enseignant
      s'adressant à un étudiant qu'il respecte.
    - Commence par une courte reconnaissance de la question (une phrase, pas plus),
      par exemple "Bonne question, voyons ça simplement." — jamais de salutation
      générique type "Bonjour, comment allez-vous" répétée à chaque message.
    - Reste naturel : évite les formules robotiques ou trop scolaires.

    STRUCTURE DE LA RÉPONSE (obligatoire pour toute explication) :
    - Utilise du Markdown : **gras** pour les termes clés, listes à puces (-) ou
      numérotées pour les étapes/éléments, et des titres courts (##) uniquement si
      la réponse couvre plusieurs sous-parties distinctes.
    - Pour une explication "simple" ou "facile à mémoriser" : commence par une
      analogie ou image concrète en une phrase, puis 3 à 5 puces claires, puis
      une phrase de conclusion mémorable si pertinent.
    - Reste concis : va à l'essentiel, pas de remplissage.
    - Base-toi en priorité sur le contenu du cours fourni ci-dessous, mais reformule
      avec tes propres mots plutôt que de résumer platement le texte source.
    - Ne révèle jamais les réponses correctes d'un quiz : guide le raisonnement plutôt
      que de donner la solution.
    - Si l'étudiant semble bloqué ou frustré, propose de basculer vers un enseignant réel.

    Ce que tu ne dois JAMAIS faire :
    - Répéter le titre du cours/module ou une question d'accroche à chaque réponse.
    - Donner une réponse quasi identique à un message précédent si la question a changé.
    - Écrire un pavé de texte sans structure visuelle.
    PROMPT;

    if ($courseContext) {
        $base .= "\n\n--- CONTENU DU COURS (référence, à reformuler, pas à réciter) ---\n{$courseContext}";
    }

    return $base;
}

    public function buildCourseContext(Course $course, ?Lesson $lesson = null): string
    {
        $context = "Titre du cours : {$course->title}\n";
        $context .= 'Description : '.($course->description ?? 'N/A')."\n";
        $context .= "Niveau : {$course->level} | Langue : {$course->language}\n\n";

        $context .= $lesson
            ? $this->buildLessonContext($lesson)
            : $this->buildCoursePlanContext($course);

        // Garde-fou anti-dépassement de contexte / coûts
        return Str::limit($context, 6000, "\n[...contenu tronqué...]");
    }

    private function buildLessonContext(Lesson $lesson): string
    {
        $lesson->loadMissing('blocks','module');

        $context = "Module : \"{$lesson->module->title}\"\n";
       $context .= "L'étudiant consulte actuellement la leçon : \"{$lesson->title}\"\n\n";

        foreach ($lesson->blocks as $block) {
            $context .= match ($block->type) {
                'heading', 'paragraph', 'quote', 'callout', 'list' => $block->content
                    ? strip_tags($block->content)."\n"
                    : '',
                'code' => $block->content
                    ? "Extrait de code ({$block->language}) :\n{$block->content}\n"
                    : '',
                'quiz' => collect($block->quiz_data['questions'] ?? [])
                    ->map(fn ($q) => 'Question de quiz associée : '.($q['question_text'] ?? ''))
                    ->implode("\n")."\n",
                default => '',
            };
        }

        return $context;
    }

    private function buildCoursePlanContext(Course $course): string
    {
        $course->loadMissing('modules.lessons');

        $context = "Plan du cours :\n";
        foreach ($course->modules as $module) {
            $context .= "- Module : {$module->title}\n";
            foreach ($module->lessons as $lesson) {
                $context .= "  · Leçon : {$lesson->title}\n";
            }
        }

        return $context;
    }
}
