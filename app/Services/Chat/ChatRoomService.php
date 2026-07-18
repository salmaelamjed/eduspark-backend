<?php

namespace App\Services\Chat;

use App\Enums\ChatMode;
use App\Enums\ChatRoomStatus;
use App\Enums\SenderType;
use App\Events\ChatMessageSent;
use App\Events\ChatRoomModeSwitched;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\User;
use App\Services\AI\GroqAIService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ChatRoomService
{
    private const AI_HISTORY_LIMIT = 10;

    public function __construct(
        private readonly GroqAIService $ai,
    ) {}

    public function findOrCreateRoom(User $student, int $courseId, ?int $lessonId): ChatRoom
    {
        $room = ChatRoom::query()
            ->where('student_id',$student->id)
            ->where('course_id',$courseId)
            ->where('status', ChatRoomStatus::ACTIVE)
             ->firstOrCreate(
            ['student_id' => $student->id, 'course_id' => $courseId],
            [
                'lesson_id' => $lessonId,
                'mode' => ChatMode::AI,
                'status' => ChatRoomStatus::ACTIVE, 
            ],
        );

        if ($lessonId && $room->lesson_id !== $lessonId) {
            $room->update(['lesson_id' => $lessonId]);
        }

        return $room->load(['course:id,title,slug', 'lesson:id,title']);
    }

    /**
     * @return array{user_message: ChatMessage, ai_message: ?ChatMessage}
     */
    public function postMessage(ChatRoom $room, User $sender, string $content): array
    {
        $senderType = $sender->id === $room->student_id ? SenderType::STUDENT : SenderType::TEACHER;

        return DB::transaction(function () use ($room, $sender, $content, $senderType) {
            $userMessage = $this->createMessage($room, $senderType, $sender->id, $content);

            broadcast(new ChatMessageSent($userMessage))->toOthers();

            $aiMessage = null;

            if ($room->isAiMode() && $senderType === SenderType::STUDENT) {
                $aiMessage = $this->generateAiReply($room);
            }

            return ['user_message' => $userMessage, 'ai_message' => $aiMessage];
        });
    }

    public function switchToHuman(ChatRoom $room): ChatRoom
    {
        $room->loadMissing('course');

        $room->update([
            'mode' => ChatMode::HUMAN,
            'teacher_id' => $room->course->teacher_id,
        ]);

        $this->createMessage(
            $room,
            SenderType::SYSTEM,
            null,
            "L'étudiant a demandé un enseignant réel. La conversation passe en direct."
        );

        broadcast(new ChatRoomModeSwitched($room));

        return $room->fresh(['teacher:id,name']);
    }

    public function switchToAi(ChatRoom $room): ChatRoom
    {
        $room->update(['mode' => ChatMode::AI]);

        $this->createMessage(
            $room,
            SenderType::SYSTEM,
            null,
            'La conversation repasse en mode assistant IA.'
        );

        broadcast(new ChatRoomModeSwitched($room));

        return $room->fresh();
    }

    private function generateAiReply(ChatRoom $room): ChatMessage
    {
        $room->loadMissing('course', 'lesson');

        $history = $room->messages()
            ->whereIn('sender_type', [SenderType::STUDENT->value, SenderType::AI->value])
            ->latest('created_at')
            ->limit(self::AI_HISTORY_LIMIT)
            ->get()
            ->reverse()
            ->map(fn (ChatMessage $m) => [
                'role' => $m->isFromAi() ? 'assistant' : 'user',
                'content' => $m->content,
            ])
            ->values()
            ->all();

        $systemPrompt = $this->ai->buildSystemPrompt(
            $this->ai->buildCourseContext($room->course, $room->lesson)
        );

        try {
            $result = $this->ai->chat([
                ['role' => 'system', 'content' => $systemPrompt],
                ...$history,
            ]);

            $content = $result['content'] !== ''
                ? $result['content']
                : "Je n'ai pas pu générer de réponse, réessaie s'il te plaît.";

            $meta = ['usage' => $result['usage']];
        } catch (\Throwable $e) {
            Log::error('AI reply generation failed', ['room_id' => $room->id, 'error' => $e->getMessage()]);
            $content = "L'assistant IA est momentanément indisponible. Tu peux basculer vers un enseignant si besoin.";
            $meta = ['error' => true];
        }

        $aiMessage = $this->createMessage($room, SenderType::AI, null, $content, $meta);

        broadcast(new ChatMessageSent($aiMessage))->toOthers();

        return $aiMessage;
    }

    private function createMessage(
        ChatRoom $room,
        SenderType $senderType,
        ?int $senderId,
        string $content,
        ?array $meta = null,
    ): ChatMessage {
        $message = $room->messages()->create([
            'sender_type' => $senderType,
            'sender_id' => $senderId,
            'content' => $content,
            'meta' => $meta,
        ]);

        $room->update(['last_message_at' => now()]);

        return $message;
    }
}