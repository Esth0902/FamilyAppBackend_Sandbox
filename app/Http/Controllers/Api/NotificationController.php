<?php

namespace App\Http\Controllers\Api;

use App\Actions\Notification\{DestroyNotificationAction, MarkAllNotificationsAsReadAction, MarkNotificationAsReadAction};
use App\Http\Controllers\Controller;
use App\Http\Requests\Notification\{IndexNotificationsRequest, MarkNotificationRequest, RegisterPushTokenRequest};
use App\Http\Resources\Notification\UserNotificationResource;
use App\Models\UserNotification;
use App\Queries\Notification\GetUnreadNotificationsQuery;
use App\Services\PushTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(IndexNotificationsRequest $request, GetUnreadNotificationsQuery $query): JsonResponse
    {
        $notifications = $query->execute(
            $request->user(),
            filter_var($request->query('all_households', false), FILTER_VALIDATE_BOOLEAN),
            (int) $request->header('X-Household-Id', 0)
        );

        return response()->json([
            'notifications' => UserNotificationResource::collection($notifications)->resolve($request),
        ]);
    }

    public function markAsRead(MarkNotificationRequest $request, UserNotification $notification, MarkNotificationAsReadAction $action): JsonResponse
    {
        $action->execute($notification);

        return response()->json(UserNotificationResource::make($notification->fresh())->resolve($request));
    }

    public function markAllAsRead(IndexNotificationsRequest $request, MarkAllNotificationsAsReadAction $action): JsonResponse
    {
        $updatedCount = $action->execute(
            $request->user(),
            filter_var($request->query('all_households', false), FILTER_VALIDATE_BOOLEAN),
            (int) $request->header('X-Household-Id', 0)
        );

        return response()->json([
            'message' => 'Notifications marquées comme lues.',
            'updated_count' => $updatedCount,
        ]);
    }

    public function destroy(MarkNotificationRequest $request, UserNotification $notification, DestroyNotificationAction $action): JsonResponse
    {
        $action->execute($notification);

        return response()->json(['message' => 'Notification supprimée.']);
    }

    public function registerPushToken(RegisterPushTokenRequest $request, PushTokenService $pushTokenService): JsonResponse
    {
        $validated = $request->validated();

        $pushTokenService->registerToken(
            userId: (int) $request->user()->id,
            token: (string) $validated['token'],
            platform: isset($validated['platform']) ? (string) $validated['platform'] : null,
            deviceName: isset($validated['device_name']) ? (string) $validated['device_name'] : null,
        );

        return response()->json(['message' => 'Token push enregistré.']);
    }

    public function revokePushToken(Request $request, PushTokenService $pushTokenService): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['nullable', 'string', 'max:255'],
        ]);

        $updatedCount = $pushTokenService->revokeToken(
            userId: (int) $request->user()->id,
            token: is_string($validated['token'] ?? null) ? (string) $validated['token'] : null,
        );

        return response()->json([
            'message' => 'Token push désactivé.',
            'updated_count' => $updatedCount,
        ]);
    }
}
