<?php
use App\Models\ChatRoom;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});
Broadcast::channel('chat-room.{roomId}', function ($user, $roomId) {
    $room = ChatRoom::find($roomId);

    return $room && ($user->role === 'admin' || $room->hasParticipant($user->id));
});

Broadcast::channel('teacher.{teacherId}', function ($user, $teacherId) {
    return (int) $user->id === (int) $teacherId;
});