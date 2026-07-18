<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\StoreChatMessageRequest;
use App\Http\Requests\Chat\StoreChatRoomRequest;
use App\Http\Resources\ChatMessageResource;
use App\Http\Resources\ChatRoomResource;
use App\Models\ChatRoom;
use App\Services\Chat\ChatRoomService;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    public function __construct(private readonly ChatRoomService $chatRoomService) {}

    /**
     * GET /api/chat/rooms
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $rooms = $user->role === 'teacher'
            ? ChatRoom::query()->forTeacher($user->id)->active()
            : ChatRoom::query()->where('student_id', $user->id)->active();

        $rooms = $rooms
            ->with(['course:id,title,slug', 'lesson:id,title', 'student:id,name', 'teacher:id,name'])
            ->latest('last_message_at')
            ->get();

        return ChatRoomResource::collection($rooms);
    }

    /**
     * POST /api/chat/rooms
     */
    public function store(StoreChatRoomRequest $request)
    {
        $room = $this->chatRoomService->findOrCreateRoom(
            $request->user(),
            $request->integer('course_id'),
            $request->integer('lesson_id') ?: null,
        );

        return new ChatRoomResource($room);
    }

    /**
     * GET /api/chat/rooms/{room}/messages
     */
    public function messages(Request $request, ChatRoom $room)
    {
        $this->authorize('view', $room);

        return ChatMessageResource::collection($room->messages()->get());
    }

    /**
     * POST /api/chat/rooms/{room}/messages
     */
    public function sendMessage(StoreChatMessageRequest $request, ChatRoom $room)
    {
        $this->authorize('sendMessage', $room);

        $result = $this->chatRoomService->postMessage(
            $room,
            $request->user(),
            $request->string('content')->toString(),
        );

        return response()->json([
            'data' => [
                'user_message' => new ChatMessageResource($result['user_message']),
                'ai_message' => $result['ai_message'] ? new ChatMessageResource($result['ai_message']) : null,
            ],
        ], 201);
    }

    /**
     * POST /api/chat/rooms/{room}/switch-to-human
     */
    public function switchToHuman(Request $request, ChatRoom $room)
    {
        $this->authorize('switchToHuman', $room);

        return new ChatRoomResource($this->chatRoomService->switchToHuman($room));
    }

    /**
     * POST /api/chat/rooms/{room}/switch-to-ai
     */
    public function switchToAi(Request $request, ChatRoom $room)
    {
        $this->authorize('switchToAi', $room);

        return new ChatRoomResource($this->chatRoomService->switchToAi($room));
    }
}