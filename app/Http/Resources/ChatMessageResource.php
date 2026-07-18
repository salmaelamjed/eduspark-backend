<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'chat_room_id' => $this->chat_room_id,
            'sender_type' => $this->sender_type->value,
            'sender_id' => $this->sender_id,
            'sender_name' => $this->sender?->name,
            'content' => $this->content,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}