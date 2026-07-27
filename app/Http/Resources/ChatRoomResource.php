<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class ChatRoomResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'mode' => $this->mode->value,
            'status' => $this->status->value,
            'course' => [
                'id' => $this->course->id,
                'title' => $this->course->title,
                'slug' => $this->course->slug,
            ],
            'lesson' => $this->whenLoaded('lesson', fn () => $this->lesson ? [
                'id' => $this->lesson->id,
                'title' => $this->lesson->title,
            ] : null),
            'student' => [
                'id' => $this->student->id,
                'name' => $this->student->name,
            ],
            'teacher' => $this->when($this->teacher_id, fn () => [
                'id' => $this->teacher?->id,
                'name' => $this->teacher?->name,
            ]),
            'last_message' => $this->whenLoaded('latestMessage', fn () => $this->latestMessage ? [
            'content' => Str::limit($this->latestMessage->content, 80),
            'sender_type' => $this->latestMessage->sender_type->value,
        ] : null),
            'created_at' => $this->created_at->toIso8601String(),
            'unread_count' => $request->user()
                ? $this->unreadCountFor($request->user())
                : 0,
            'last_message_at' => $this->last_message_at?->toIso8601String(),
        ];
    }
}
