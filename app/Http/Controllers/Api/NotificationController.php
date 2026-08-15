<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Notification\NotificationResource;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
{
    $category = $request->string('category')->toString();

    $notifications = $request->user()
        ->notifications()
        ->when($category, fn ($q) =>
            $q->whereJsonContains('data->category', $category)
        )
        ->latest()
        ->paginate($request->integer('per_page', 20));

    return NotificationResource::collection($notifications);
}

   public function unreadCount(Request $request)
{
    $category = $request->string('category')->toString();

    $query = $request->user()->unreadNotifications();

    if ($category) {
        $query->whereJsonContains('data->category', $category);
    }

    return response()->json(['count' => $query->count()]);
}

    public function markAsRead(Request $request, string $id)
    {
        $request->user()->notifications()->findOrFail($id)->markAsRead();

        return response()->json(['success' => true]);
    }

    public function markAllAsRead(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, string $id)
    {
        $request->user()->notifications()->findOrFail($id)->delete();

        return response()->json(['success' => true]);
    }
}
