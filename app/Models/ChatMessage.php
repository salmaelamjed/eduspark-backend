<?php

namespace App\Models;

use App\Enums\SenderType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    protected $fillable = ['chat_room_id', 'sender_type', 'sender_id', 'content', 'meta'];

    protected $casts = [
        'sender_type' => SenderType::class,
        'meta' => 'array',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(ChatRoom::class, 'chat_room_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function isFromAi(): bool
    {
        return $this->sender_type === SenderType::AI;
    }
}