<?php

namespace App\Actions\Household;

use App\Models\BudgetSetting;
use App\Models\Household;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RemoveMemberAction
{
    public function execute(Household $household, User $member): void
    {
        DB::transaction(function () use ($household, $member): void {
            $household->users()->detach((int) $member->id);

            BudgetSetting::query()
                ->where('household_id', (int) $household->id)
                ->where('user_id', (int) $member->id)
                ->delete();
        });
    }
}

