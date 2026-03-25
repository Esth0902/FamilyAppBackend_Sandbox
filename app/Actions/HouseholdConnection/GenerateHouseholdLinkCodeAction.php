<?php

namespace App\Actions\HouseholdConnection;

use App\Models\Household;
use App\Models\HouseholdLinkCode;
use App\Models\HouseholdLinkRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GenerateHouseholdLinkCodeAction
{
    private const LINK_CODE_LENGTH = 8;
    private const LINK_CODE_TTL_HOURS = 24;
    private const REQUEST_STATUS_PENDING = 'pending';

    public function execute(Household $household, User $actor): HouseholdLinkCode
    {
        $code = DB::transaction(function () use ($household, $actor): HouseholdLinkCode {
            /** @var Household $lockedHousehold */
            $lockedHousehold = Household::query()
                ->whereKey((int) $household->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureHouseholdCanStartConnectionFlow($lockedHousehold);

            $code = $this->findReusableCode((int) $lockedHousehold->id);
            if (!$code instanceof HouseholdLinkCode) {
                $code = HouseholdLinkCode::query()->create([
                    'household_id' => (int) $lockedHousehold->id,
                    'created_by_user_id' => (int) $actor->id,
                    'code' => $this->generateUniqueCode(),
                    'expires_at' => now()->addHours(self::LINK_CODE_TTL_HOURS),
                ]);
            }

            return $code;
        });

        return $code;
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

    private function findReusableCode(int $householdId): ?HouseholdLinkCode
    {
        return HouseholdLinkCode::query()
            ->where('household_id', $householdId)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();
    }

    private function generateUniqueCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        do {
            $code = '';
            for ($index = 0; $index < self::LINK_CODE_LENGTH; $index++) {
                $charIndex = random_int(0, strlen($alphabet) - 1);
                $code .= $alphabet[$charIndex];
            }

            $exists = HouseholdLinkCode::query()->where('code', $code)->exists();
        } while ($exists);

        return $code;
    }
}
