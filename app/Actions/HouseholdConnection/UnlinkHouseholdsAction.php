<?php

namespace App\Actions\HouseholdConnection;

use App\Events\HouseholdConnection\HouseholdsUnlinkedEvent;
use App\Models\Household;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UnlinkHouseholdsAction
{
    /**
     * @return array{message:string}
     */
    public function execute(Household $household, User $actor): array
    {
        $eventPayload = null;

        DB::transaction(function () use ($household, $actor, &$eventPayload): void {
            /** @var Household $sourceHousehold */
            $sourceHousehold = Household::query()
                ->whereKey((int) $household->id)
                ->lockForUpdate()
                ->firstOrFail();

            $linkedHouseholdId = (int) ($sourceHousehold->linked_household_id ?? 0);
            if ($linkedHouseholdId <= 0) {
                throw ValidationException::withMessages([
                    'connection' => ['Aucun foyer connecte a dissocier.'],
                ]);
            }

            /** @var Household|null $linkedHousehold */
            $linkedHousehold = Household::query()
                ->whereKey($linkedHouseholdId)
                ->lockForUpdate()
                ->first();

            $sourceHousehold->forceFill(['linked_household_id' => null])->save();
            if ($linkedHousehold instanceof Household
                && (int) ($linkedHousehold->linked_household_id ?? 0) === (int) $sourceHousehold->id) {
                $linkedHousehold->forceFill(['linked_household_id' => null])->save();
            }

            $eventPayload = [
                'source_household_id' => (int) $sourceHousehold->id,
                'source_household_name' => (string) $sourceHousehold->name,
                'linked_household_id' => $linkedHousehold instanceof Household ? (int) $linkedHousehold->id : null,
                'linked_household_name' => $linkedHousehold instanceof Household ? (string) $linkedHousehold->name : null,
                'actor_user_id' => (int) $actor->id,
                'actor_user_name' => (string) ($actor->name ?? 'Un parent'),
            ];
        });

        if (is_array($eventPayload)) {
            event(new HouseholdsUnlinkedEvent(
                sourceHouseholdId: (int) $eventPayload['source_household_id'],
                sourceHouseholdName: (string) $eventPayload['source_household_name'],
                linkedHouseholdId: $eventPayload['linked_household_id'] !== null
                    ? (int) $eventPayload['linked_household_id']
                    : null,
                linkedHouseholdName: $eventPayload['linked_household_name'] !== null
                    ? (string) $eventPayload['linked_household_name']
                    : null,
                actorUserId: (int) $eventPayload['actor_user_id'],
                actorUserName: (string) $eventPayload['actor_user_name'],
            ));
        }

        return [
            'message' => 'La liaison entre foyers a été supprimée.',
        ];
    }
}
