<?php

namespace App\Events;

use App\Http\Resources\ChatRoomResource;
use App\Models\ChatRoom;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatRoomModeSwitched implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly ChatRoom $room) {}

    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('chat-room.'.$this->room->id)];

        if ($this->room->teacher_id) {
            $channels[] = new PrivateChannel('teacher.'.$this->room->teacher_id);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'chat.mode-switched';
    }

    public function broadcastWith(): array
    {
        return (new ChatRoomResource($this->room->load('course:id,title')))->resolve();
    }
}
