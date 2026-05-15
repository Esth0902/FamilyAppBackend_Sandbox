<?php

namespace App\Actions\HouseholdConnection;

use App\Events\HouseholdConnection\HouseholdLinkRequestedEvent;
use App\Models\Household;
use App\Models\HouseholdLinkCode;
use App\Models\HouseholdLinkRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitHouseholdLinkAction
{
    private const REQUEST_STATUS_PENDING = 'pending';

    public function execute(Household $household, User $requestingUser, string $normalizedCode): HouseholdLinkRequest
    {
        $requesterHouseholdId = (int) $household->id;
        $eventPayload = null;

        /** @var HouseholdLinkRequest $createdRequest */
        $createdRequest = DB::transaction(function () use (
            $requesterHouseholdId,
            $requestingUser,
            $normalizedCode,
            &$eventPayload
        ): HouseholdLinkRequest {
            /** @var Household $requesterHousehold */
            $requesterHousehold = Household::query()
                ->whereKey($requesterHouseholdId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureHouseholdCanStartConnectionFlow($requesterHousehold);

            /** @var HouseholdLinkCode|null $code */
            $code = HouseholdLinkCode::query()
                ->where('code', $normalizedCode)
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if (!$code instanceof HouseholdLinkCode) {
                throw ValidationException::withMessages([
                    'code' => ['Ce code de liaison est invalide ou expiré.'],
                ]);
            }

            $targetHouseholdId = (int) $code->household_id;
            if ($targetHouseholdId === $requesterHouseholdId) {
                throw ValidationException::withMessages([
                    'code' => ['Ce code appartient déjà à ton foyer.'],
                ]);
            }

            /** @var Household $targetHousehold */
            $targetHousehold = Household::query()
                ->whereKey($targetHouseholdId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureHouseholdCanStartConnectionFlow($targetHousehold);

            $requestAlreadyExists = HouseholdLinkRequest::query()
                ->where('status', self::REQUEST_STATUS_PENDING)
                ->where(function ($query) use ($requesterHouseholdId, $targetHouseholdId): void {
                    $query
                        ->where(function ($directQuery) use ($requesterHouseholdId, $targetHouseholdId): void {
                            $directQuery
                                ->where('from_household_id', $requesterHouseholdId)
                                ->where('to_household_id', $targetHouseholdId);
                        })
                        ->orWhere(function ($inverseQuery) use ($requesterHouseholdId, $targetHouseholdId): void {
                            $inverseQuery
                                ->where('from_household_id', $targetHouseholdId)
                                ->where('to_household_id', $requesterHouseholdId);
                        });
                })
                ->exists();
            if ($requestAlreadyExists) {
                throw ValidationException::withMessages([
                    'code' => ['Une demande de liaison est déjà en attente entre ces deux foyers.'],
                ]);
            }

            $targetParentIds = $this->resolveParentUserIds($targetHouseholdId);
            if (count($targetParentIds) === 0) {
                throw ValidationException::withMessages([
                    'household' => ['Aucun parent n\'est disponible pour valider la liaison.'],
                ]);
            }

            $createdRequest = HouseholdLinkRequest::query()->create([
                'from_household_id' => $requesterHouseholdId,
                'to_household_id' => $targetHouseholdId,
                'requested_by_user_id' => (int) $requestingUser->id,
                'household_link_code_id' => (int) $code->id,
                'status' => self::REQUEST_STATUS_PENDING,
            ]);

            $code->forceFill([
                'used_at' => now(),
                'used_by_household_id' => $requesterHouseholdId,
            ])->save();

            $eventPayload = [
                'link_request_id' => (int) $createdRequest->id,
                'requester_household_id' => $requesterHouseholdId,
                'requester_household_name' => (string) $requesterHousehold->name,
                'target_household_id' => $targetHouseholdId,
                'target_household_name' => (string) $targetHousehold->name,
                'requested_by_user_id' => (int) $requestingUser->id,
                'requested_by_user_name' => (string) ($requestingUser->name ?? 'Un parent'),
                'target_parent_ids' => $targetParentIds,
            ];

            return $createdRequest;
        });

        if (is_array($eventPayload)) {
            event(new HouseholdLinkRequestedEvent(
                linkRequestId: (int) $eventPayload['link_request_id'],
                requesterHouseholdId: (int) $eventPayload['requester_household_id'],
                requesterHouseholdName: (string) $eventPayload['requester_household_name'],
                targetHouseholdId: (int) $eventPayload['target_household_id'],
                targetHouseholdName: (string) $eventPayload['target_household_name'],
                requestedByUserId: (int) $eventPayload['requested_by_user_id'],
                requestedByUserName: (string) $eventPayload['requested_by_user_name'],
                targetParentIds: (array) $eventPayload['target_parent_ids'],
            ));
        }

        $createdRequest->loadMissing(['fromHousehold:id,name', 'toHousehold:id,name']);

        return $createdRequest;
    }

    private function ensureHouseholdCanStartConnectionFlow(Household $household): void
    {
        if ($this->resolveConnectedHousehold($household) instanceof Household) {
            throw ValidationException::withMessages([
                'connection' => ['Ce foyer est déjà connecté à un autre foyer.'],
            ]);
        }

        $pendingRequest = HouseholdLinkRequest::query()
            ->where('status', self::REQUEST_STATUS_PENDING)
            ->where(function ($query) use ($household): void {
                $query
                    ->where('from_household_id', (int) $household->id)
                    ->orWhere('to_household_id', (int) $household->id);
            })
            ->exists();

        if ($pendingRequest) {
            throw ValidationException::withMessages([
                'connection' => ['Une demande de liaison est déjà en attente pour ce foyer.'],
            ]);
        }
    }

    private function resolveConnectedHousehold(Household $household): ?Household
    {
        $linkedHouseholdId = (int) ($household->linked_household_id ?? 0);
        if ($linkedHouseholdId <= 0) {
            return null;
        }

        $linkedHousehold = Household::query()->find($linkedHouseholdId);
        if (!$linkedHousehold instanceof Household) {
            return null;
        }

        return (int) ($linkedHousehold->linked_household_id ?? 0) === (int) $household->id
            ? $linkedHousehold
            : null;
    }

    /**
     * @return array<int, int>
     */
    private function resolveParentUserIds(int $householdId): array
    {
        return DB::table('household_user')
            ->where('household_id', $householdId)
            ->where('role', User::ROLE_PARENT)
            ->pluck('user_id')
            ->map(static fn ($userId): int => (int) $userId)
            ->filter(static fn (int $userId): bool => $userId > 0)
            ->values()
            ->all();
    }

}
