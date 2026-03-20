<?php

namespace App\Actions\Auth;

use App\Models\BudgetSetting;
use App\Models\Household;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;

class RegisterUserAction
{
    /**
     * @param  array{name:string,email:string,password:string}  $validated
     * @return array{user:User,token:string}
     */
    public function execute(array $validated): array
    {
        return DB::transaction(function () use ($validated): array {
            $user = User::query()->create([
                'name' => (string) $validated['name'],
                'email' => (string) $validated['email'],
                'password' => (string) $validated['password'],
            ]);

            $household = Household::query()->create([
                'name' => $this->resolveDefaultHouseholdName($user),
            ]);

            $household->users()->attach((int) $user->id, [
                'role' => User::ROLE_PARENT,
                'nickname' => (string) ($user->name ?? 'Parent'),
            ]);

            BudgetSetting::query()->firstOrCreate(
                [
                    'household_id' => (int) $household->id,
                    'user_id' => (int) $user->id,
                ],
                [
                    'base_amount' => 0,
                    'recurrence' => 'weekly',
                    'reset_day' => 1,
                    'allow_advances' => false,
                    'max_advance_amount' => 0,
                ]
            );

            event(new Registered($user));

            return [
                'user' => $user->fresh()->load('households'),
                'token' => $user->createToken('mobile')->plainTextToken,
            ];
        });
    }

    private function resolveDefaultHouseholdName(User $user): string
    {
        $name = trim((string) ($user->name ?? ''));
        return $name !== '' ? "Foyer de {$name}" : 'Mon foyer';
    }
}
