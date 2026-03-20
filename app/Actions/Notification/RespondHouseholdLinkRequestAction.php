<?php

namespace App\Actions\Notification;

use App\Models\Household;
use App\Models\HouseholdLinkRequest;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\NotificationDispatchService;
use App\Services\RealtimePublisher;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RespondHouseholdLinkRequestAction
{
    private const HOUSEHOLD_LINK_REQUEST_TYPE = 'household_link_request';
    private const HOUSEHOLD_LINK_RESPONSE_TYPE = 'household_link_request_responded';

    public function __construct(
        private readonly RealtimePublisher $realtimePublisher,
        private readonly NotificationDispatchService $notificationDispatchService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(UserNotification $notification, User $user, string $action): array
    {
        if ((string) $notification->type !== self::HOUSEHOLD_LINK_REQUEST_TYPE) {
            throw ValidationException::withMessages([
                'notification' => ["Cette notification n'est pas une demande de liaison de foyer."],
            ]);
        }

        $now = now();
        $notificationsToPublish = collect();
        $householdRealtimePayload = null;

        $responsePayload = DB::transaction(function () use (
            $notification,
            $user,
            $action,
            $now,
            &$notificationsToPublish,
            &$householdRealtimePayload
        ): array {
            /** @var UserNotification $lockedNotification */
            $lockedNotification = UserNotification::query()
                ->whereKey($notification->id)
                ->lockForUpdate()
                ->firstOrFail();

            $data = is_array($lockedNotification->data) ? $lockedNotification->data : [];
            $currentStatus = (string) ($data['status'] ?? 'pending');
            if (in_array($currentStatus, ['accepted', 'refused'], true)) {
                throw ValidationException::withMessages([
                    'notification' => ['Cette demande a deja ete traitee.'],
                ]);
            }

            $linkRequestId = (int) ($data['link_request_id'] ?? 0);
            if ($linkRequestId <= 0) {
                throw ValidationException::withMessages([
                    'notification' => ['Cette demande est invalide.'],
                ]);
            }

            /** @var HouseholdLinkRequest $linkRequest */
            $linkRequest = HouseholdLinkRequest::query()
                ->whereKey($linkRequestId)
                ->with(['fromHousehold:id,name,linked_household_id', 'toHousehold:id,name,linked_household_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if ((string) $linkRequest->status !== 'pending') {
                throw ValidationException::withMessages([
                    'notification' => ['Cette demande n est plus en attente.'],
                ]);
            }

            $targetHouseholdId = (int) $linkRequest->to_household_id;
            $isTargetParent = $user->households()
                ->where('households.id', $targetHouseholdId)
                ->wherePivot('role', User::ROLE_PARENT)
                ->exists();
            if (!$isTargetParent) {
                throw new AuthorizationException('Seul un parent du foyer cible peut traiter cette demande.');
            }

            /** @var Household $fromHousehold */
            $fromHousehold = Household::query()
                ->whereKey((int) $linkRequest->from_household_id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var Household $toHousehold */
            $toHousehold = Household::query()
                ->whereKey((int) $linkRequest->to_household_id)
                ->lockForUpdate()
                ->firstOrFail();

            $isAccepted = $action === 'accept';
            if ($isAccepted) {
                $fromLinkedHouseholdId = (int) ($fromHousehold->linked_household_id ?? 0);
                $toLinkedHouseholdId = (int) ($toHousehold->linked_household_id ?? 0);

                if (
                    ($fromLinkedHouseholdId > 0 && $fromLinkedHouseholdId !== (int) $toHousehold->id)
                    || ($toLinkedHouseholdId > 0 && $toLinkedHouseholdId !== (int) $fromHousehold->id)
                ) {
                    throw ValidationException::withMessages([
                        'connection' => ['Un des deux foyers est deja lie a un autre foyer.'],
                    ]);
                }

                $fromHousehold->forceFill(['linked_household_id' => (int) $toHousehold->id])->save();
                $toHousehold->forceFill(['linked_household_id' => (int) $fromHousehold->id])->save();
            }

            $newStatus = $isAccepted ? 'accepted' : 'refused';
            $linkRequest->forceFill([
                'status' => $newStatus,
                'responded_by_user_id' => (int) $user->id,
                'responded_at' => $now,
            ])->save();

            $requestNotifications = UserNotification::query()
                ->where('type', self::HOUSEHOLD_LINK_REQUEST_TYPE)
                ->where('data->link_request_id', (int) $linkRequest->id)
                ->lockForUpdate()
                ->get();

            foreach ($requestNotifications as $requestNotification) {
                $requestData = is_array($requestNotification->data) ? $requestNotification->data : [];
                $requestData['status'] = $newStatus;
                $requestData['responded_at'] = $now->toIso8601String();
                $requestData['responded_action'] = $action;

                $requestNotification->forceFill([
                    'data' => $requestData,
                    'read_at' => $now,
                ])->save();
            }

            $requesterParentIds = $this->notificationDispatchService->resolveParentUserIds((int) $fromHousehold->id);
            foreach ($requesterParentIds as $requesterParentId) {
                $notificationToRequester = $this->notificationDispatchService->createUserNotification(
                    userId: $requesterParentId,
                    householdId: (int) $fromHousehold->id,
                    type: self::HOUSEHOLD_LINK_RESPONSE_TYPE,
                    title: $isAccepted ? 'Liaison de foyer acceptee' : 'Liaison de foyer refusee',
                    body: $isAccepted
                        ? sprintf(
                            'Le foyer %s a accepte votre demande de liaison.',
                            (string) $toHousehold->name
                        )
                        : sprintf(
                            'Le foyer %s a refuse votre demande de liaison.',
                            (string) $toHousehold->name
                        ),
                    data: [
                        'status' => $newStatus,
                        'link_request_id' => (int) $linkRequest->id,
                        'requester_household_id' => (int) $fromHousehold->id,
                        'requester_household_name' => (string) $fromHousehold->name,
                        'target_household_id' => (int) $toHousehold->id,
                        'target_household_name' => (string) $toHousehold->name,
                        'responded_by_user_id' => (int) $user->id,
                        'responded_by_user_name' => (string) ($user->name ?? 'Un parent'),
                    ],
                );
                $notificationsToPublish->push($notificationToRequester);
            }

            $householdRealtimePayload = [
                'from_household_id' => (int) $fromHousehold->id,
                'to_household_id' => (int) $toHousehold->id,
                'status' => $newStatus,
            ];

            return [
                'message' => $isAccepted ? 'Demande acceptee.' : 'Demande refusee.',
                'request' => [
                    'id' => (int) $linkRequest->id,
                    'status' => $newStatus,
                    'from_household' => [
                        'id' => (int) $fromHousehold->id,
                        'name' => (string) $fromHousehold->name,
                    ],
                    'to_household' => [
                        'id' => (int) $toHousehold->id,
                        'name' => (string) $toHousehold->name,
                    ],
                ],
            ];
        });

        DB::afterCommit(function () use ($notificationsToPublish, $householdRealtimePayload): void {
            foreach ($notificationsToPublish as $notificationToPublish) {
                if ($notificationToPublish instanceof UserNotification) {
                    $this->notificationDispatchService->publishNotificationCreated($notificationToPublish);
                }
            }

            if (is_array($householdRealtimePayload)) {
                $fromHouseholdId = (int) ($householdRealtimePayload['from_household_id'] ?? 0);
                $toHouseholdId = (int) ($householdRealtimePayload['to_household_id'] ?? 0);

                if ($fromHouseholdId > 0) {
                    $this->realtimePublisher->publishHousehold(
                        householdId: $fromHouseholdId,
                        module: 'household',
                        type: 'connection_updated',
                        payload: $householdRealtimePayload,
                    );
                }
                if ($toHouseholdId > 0) {
                    $this->realtimePublisher->publishHousehold(
                        householdId: $toHouseholdId,
                        module: 'household',
                        type: 'connection_updated',
                        payload: $householdRealtimePayload,
                    );
                }
            }
        });

        return $responsePayload;
    }
}

