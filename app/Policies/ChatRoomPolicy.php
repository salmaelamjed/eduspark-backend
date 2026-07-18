<?php

namespace App\Policies;

use App\Models\ChatRoom;
use App\Models\User;

class ChatRoomPolicy
{
    public function view(User $user, ChatRoom $room): bool
    {
        return $user->role === 'admin' || $room->hasParticipant($user->id);
    }

    public function sendMessage(User $user, ChatRoom $room): bool
    {
        return $room->hasParticipant($user->id) && $room->isActive();
    }

    public function switchToHuman(User $user, ChatRoom $room): bool
    {
        return $user->id === $room->student_id && $room->isAiMode();
    }

    public function switchToAi(User $user, ChatRoom $room): bool
    {
        return $room->hasParticipant($user->id) && $room->isHumanMode();
    }
}