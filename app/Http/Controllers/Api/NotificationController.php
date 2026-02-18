<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function pending(Request $request): JsonResponse
    {
        $userId = (int)$request->user()->id;
        $now = now();

        $notifications = UserNotification::query()
            ->where('user_id', $userId)
            ->whereNull('sent_at')
            ->where(function ($query) use ($now): void {
                $query->whereNull('scheduled_for')
                    ->orWhere('scheduled_for', '<=', $now);
            })
            ->orderBy('created_at')
            ->limit(30)
            ->get();

        if ($notifications->isNotEmpty()) {
            UserNotification::query()
                ->whereIn('id', $notifications->pluck('id'))
                ->update(['sent_at' => $now]);
        }

        return response()->json([
            'notifications' => $notifications->map(fn(UserNotification $notification): array => [
                'id' => $notification->id,
                'type' => $notification->type,
                'title' => $notification->title,
                'body' => $notification->body,
                'data' => $notification->data ?? [],
                'scheduled_for' => optional($notification->scheduled_for)->toIso8601String(),
                'read_at' => optional($notification->read_at)->toIso8601String(),
                'created_at' => optional($notification->created_at)->toIso8601String(),
            ])->values(),
        ]);
    }

    public function read(Request $request, UserNotification $notification): JsonResponse
    {
        if ((int)$notification->user_id !== (int)$request->user()->id) {
            return response()->json(['message' => 'Acces refuse.'], 403);
        }

        if (is_null($notification->read_at)) {
            $notification->update(['read_at' => now()]);
        }

        return response()->json(['message' => 'Notification lue.']);
    }
}

