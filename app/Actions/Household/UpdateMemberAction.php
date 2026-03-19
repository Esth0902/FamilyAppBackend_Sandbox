<?php

namespace App\Actions\Household;

use App\Models\BudgetSetting;
use App\Models\Household;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UpdateMemberAction
{
    /**
     * @param  array<string, mixed>  $validated
     * @return array{member: User, generated_email: string|null}
     */
    public function execute(Household $household, User $member, array $validated): array
    {
        if (count($validated) === 0) {
            throw ValidationException::withMessages([
                'member' => ['Aucune modification demandée.'],
            ]);
        }

        $memberInHousehold = $household->users()
            ->where('users.id', $member->id)
            ->first();
        if (!$memberInHousehold) {
            abort(404, 'Membre introuvable pour ce foyer.');
        }

        $currentRole = (string) ($memberInHousehold->pivot->role ?? User::ROLE_CHILD);
        $nextRole = array_key_exists('role', $validated)
            ? (string) $validated['role']
            : $currentRole;

        if ($currentRole === User::ROLE_PARENT && $nextRole !== User::ROLE_PARENT) {
            $this->ensureParentCanBeRemoved($household, (int) $member->id);
        }

        $name = array_key_exists('name', $validated)
            ? trim((string) $validated['name'])
            : (string) $member->name;
        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => ['Le nom du membre est obligatoire.'],
            ]);
        }

        $updates = [];
        if (array_key_exists('name', $validated)) {
            $updates['name'] = $name;
        }

        $generatedEmail = null;
        if (array_key_exists('email', $validated)) {
            $providedEmail = $this->normalizeEmailInput($validated['email'] ?? null) ?? '';
            if ($providedEmail === '') {
                $providedEmail = $this->generateUniqueHouseholdEmail($name);
                $generatedEmail = $providedEmail;
            }

            $updates['email'] = $providedEmail;
        }

        $freshMember = DB::transaction(function () use (
            $household,
            $member,
            $currentRole,
            $nextRole,
            $updates,
            $validated,
            $name
        ): User {
            if (!empty($updates)) {
                $member->forceFill($updates)->save();
            }

            $pivotUpdates = [];
            if (array_key_exists('role', $validated)) {
                $pivotUpdates['role'] = $nextRole;
            }
            if (array_key_exists('nickname', $validated)) {
                $nickname = trim((string) ($validated['nickname'] ?? ''));
                $pivotUpdates['nickname'] = $nickname !== '' ? $nickname : $name;
            }

            if (!empty($pivotUpdates)) {
                $household->users()->updateExistingPivot($member->id, $pivotUpdates);
            }

            if ($currentRole !== $nextRole) {
                if ($nextRole === User::ROLE_CHILD) {
                    BudgetSetting::query()->firstOrCreate(
                        [
                            'household_id' => $household->id,
                            'user_id' => $member->id,
                        ],
                        [
                            'base_amount' => 0,
                            'recurrence' => 'weekly',
                            'reset_day' => 1,
                            'allow_advances' => false,
                            'max_advance_amount' => 0,
                        ]
                    );
                } else {
                    BudgetSetting::query()
                        ->where('household_id', $household->id)
                        ->where('user_id', $member->id)
                        ->delete();
                }
            }

            return $household->users()
                ->where('users.id', $member->id)
                ->firstOrFail();
        });

        return [
            'member' => $freshMember,
            'generated_email' => $generatedEmail,
        ];
    }

    private function ensureParentCanBeRemoved(Household $household, int $memberId): void
    {
        if (!$this->hasOtherParent($household, $memberId)) {
            throw ValidationException::withMessages([
                'role' => ['Le foyer doit conserver au moins un parent. Désignez un nouveau parent ou supprimez le foyer.'],
            ]);
        }
    }

    private function hasOtherParent(Household $household, int $memberId): bool
    {
        return $household->users()
            ->wherePivot('role', User::ROLE_PARENT)
            ->where('users.id', '!=', $memberId)
            ->exists();
    }

    private function generateUniqueHouseholdEmail(string $name): string
    {
        $cleanName = Str::slug($name);

        do {
            $randomCode = Str::lower(Str::random(4));
            $email = "{$cleanName}.{$randomCode}@family.app";
        } while (User::where('email', $email)->exists());

        return $email;
    }

    private function normalizeEmailInput(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalized = mb_strtolower(trim($value));

        return $normalized !== '' ? $normalized : null;
    }
}
