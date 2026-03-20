<?php

namespace App\Actions\Auth;

use App\Models\BudgetSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DestroyUserAccountAction
{
    public function execute(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $user->tokens()->delete();
            $user->households()->detach();

            BudgetSetting::query()
                ->where('user_id', (int) $user->id)
                ->delete();

            $user->delete();
        });
    }
}
