<?php

namespace App\Actions\HouseholdConnection;

use App\Events\HouseholdConnection\HouseholdLinkRespondedEvent;
use App\Models\Household;
use App\Models\HouseholdLinkRequest;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RespondToHouseholdLinkAction
{
    private const HOUSEHOLD_LINK_REQUEST_TYPE = 'household_link_request';

    /**
     * @return array<string, mixed>
     */
    public function execute(UserNotification $notification, User $user, string $action): array
    {
        if ((string) $notification->type !== self::HOUSEHOLD_LINK_REQUEST_TYPE) {
            throw ValidationException::withMessages([
                'notification' => ['Cette notification n\'est pas une demande de liaison de foyer.'],
            ]);
        }

        $now = now();
        $eventPayload = null;

        $responsePayload = DB::transaction(function () use (
            $notification,
            $user,
            $action,
            $now,
            &$eventPayload
        ): array {
            /** @var UserNotification $lockedNotification */
            $lockedNotification = UserNotification::query()
                ->whereKey((int) $notification->id)
                ->lockForUpdate()
                ->firstOrFail();

            $data = is_array($lockedNotification->data) ? $lockedNotification->data : [];
            $currentStatus = (string) ($data['status'] ?? 'pending');
            if (in_array($currentStatus, ['accepted', 'refused'], true)) {
                throw ValidationException::withMessages([
                    'notification' => ['Cette demande a déjà été traitée.'],
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
                ->lockForUpdate()
                ->firstOrFail();

            if ((string) $linkRequest->status !== 'pending') {
                throw ValidationException::withMessages([
                    'notification' => ['Cette demande n\'est plus en attente.'],
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
                        'connection' => ['Un des deux foyers est déjà lié à un autre foyer.'],
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

            $eventPayload = [
                'link_request_id' => (int) $linkRequest->id,
                'from_household_id' => (int) $fromHousehold->id,
                'from_household_name' => (string) $fromHousehold->name,
                'to_household_id' => (int) $toHousehold->id,
                'to_household_name' => (string) $toHousehold->name,
                'responded_by_user_id' => (int) $user->id,
                'responded_by_user_name' => (string) ($user->name ?? 'Un parent'),
                'status' => $newStatus,
            ];

            return [
                'message' => $isAccepted ? 'Demande acceptée.' : 'Demande refusée.',
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

        if (is_array($eventPayload)) {
            event(new HouseholdLinkRespondedEvent(
                linkRequestId: (int) $eventPayload['link_request_id'],
                fromHouseholdId: (int) $eventPayload['from_household_id'],
                fromHouseholdName: (string) $eventPayload['from_household_name'],
                toHouseholdId: (int) $eventPayload['to_household_id'],
                toHouseholdName: (string) $eventPayload['to_household_name'],
                respondedByUserId: (int) $eventPayload['responded_by_user_id'],
                respondedByUserName: (string) $eventPayload['responded_by_user_name'],
                status: (string) $eventPayload['status'],
            ));
        }

        return $responsePayload;
    }
}
