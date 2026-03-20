<?php

namespace App\Http\Controllers\Api;

use App\Actions\Notification\{RespondHouseholdDeletionAction, RespondHouseholdInviteAction, RespondHouseholdLinkRequestAction, RespondTaskReassignmentInviteAction};
use App\Http\Controllers\Controller;
use App\Http\Requests\Notification\MarkNotificationRequest;
use App\Models\UserNotification;
use Illuminate\Http\JsonResponse;

class NotificationResolutionController extends Controller
{
    public function respondHouseholdInvite(MarkNotificationRequest $request, UserNotification $notification, RespondHouseholdInviteAction $action): JsonResponse
    {
        $validated = $request->validate(['action' => ['required', 'in:accept,refuse']]);
        return response()->json($action->execute($notification, $request->user(), (string) $validated['action']));
    }

    public function respondHouseholdLinkRequest(MarkNotificationRequest $request, UserNotification $notification, RespondHouseholdLinkRequestAction $action): JsonResponse
    {
        $validated = $request->validate(['action' => ['required', 'in:accept,refuse']]);
        return response()->json($action->execute($notification, $request->user(), (string) $validated['action']));
    }

    public function respondTaskReassignmentInvite(MarkNotificationRequest $request, UserNotification $notification, RespondTaskReassignmentInviteAction $action): JsonResponse
    {
        $validated = $request->validate(['action' => ['required', 'in:accept,refuse']]);
        return response()->json($action->execute($notification, $request->user(), (string) $validated['action']));
    }

    public function respondHouseholdDeletion(MarkNotificationRequest $request, UserNotification $notification, RespondHouseholdDeletionAction $action): JsonResponse
    {
        $validated = $request->validate(['action' => ['required', 'in:accept,refuse,cancel']]);
        return response()->json($action->execute($notification, $request->user(), (string) $validated['action']));
    }
}

