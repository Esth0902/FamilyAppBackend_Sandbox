<?php

namespace App\Http\Controllers\Api;

use App\Actions\Notification\{DestroyNotificationAction, MarkAllNotificationsAsReadAction, MarkNotificationAsReadAction};
use App\Http\Controllers\Controller;
use App\Http\Requests\Notification\{IndexNotificationsRequest, MarkNotificationRequest};
use App\Http\Resources\Notification\UserNotificationResource;
use App\Models\UserNotification;
use App\Queries\Notification\GetUnreadNotificationsQuery;
use Illuminate\Http\JsonResponse;

class NotificationController extends Controller
{
    public function index(IndexNotificationsRequest $request, GetUnreadNotificationsQuery $query): JsonResponse
    {
        $notifications = $query->execute($request->user(), filter_var($request->query('all_households', false), FILTER_VALIDATE_BOOLEAN), (int) $request->header('X-Household-Id', 0));
        return response()->json(['notifications' => UserNotificationResource::collection($notifications)->resolve($request)]);
    }

    public function markAsRead(MarkNotificationRequest $request, UserNotification $notification, MarkNotificationAsReadAction $action): JsonResponse
    {
        $action->execute($notification);
        return response()->json(UserNotificationResource::make($notification->fresh())->resolve($request));
    }

    public function markAllAsRead(IndexNotificationsRequest $request, MarkAllNotificationsAsReadAction $action): JsonResponse
    {
        $updatedCount = $action->execute($request->user(), filter_var($request->query('all_households', false), FILTER_VALIDATE_BOOLEAN), (int) $request->header('X-Household-Id', 0));
        return response()->json(['message' => 'Notifications marquées comme lues.', 'updated_count' => $updatedCount]);
    }

    public function destroy(MarkNotificationRequest $request, UserNotification $notification, DestroyNotificationAction $action): JsonResponse
    {
        $action->execute($notification);
        return response()->json(['message' => 'Notification supprimée.']);
    }
}

