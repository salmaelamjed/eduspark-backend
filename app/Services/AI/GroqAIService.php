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
        Ton rôle est d'aider l'étudiant à comprendre le contenu du cours ci-dessous.
        Règles strictes :
        - Réponds en priorité à partir du contenu du cours fourni.
        - Si la question sort du cadre du cours, réponds quand même utilement mais précise
          que ce n'est pas directement couvert par le cours.
        - Ne révèle jamais les réponses correctes d'un quiz : guide le raisonnement plutôt
          que de donner la solution.
        - Reste concis, structuré, et pédagogue (exemples concrets si utile).
        - Si l'étudiant semble bloqué ou frustré, propose-lui de basculer vers un enseignant réel.
        PROMPT;

        if ($courseContext) {
            $base .= "\n\n--- CONTENU DU COURS ---\n{$courseContext}";
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
        $lesson->loadMissing('blocks');

        $context = "L'étudiant consulte actuellement la leçon : \"{$lesson->title}\"\n\n";

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