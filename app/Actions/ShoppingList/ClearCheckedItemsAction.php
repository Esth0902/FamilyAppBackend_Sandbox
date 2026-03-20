<?php

namespace App\Actions\ShoppingList;

use App\Models\Household;
use App\Models\ShoppingList;
use App\Models\User;
use App\Services\RealtimePublisher;
use Illuminate\Support\Facades\DB;

class ClearCheckedItemsAction
{
    public function __construct(private readonly RealtimePublisher $realtimePublisher)
    {
    }

    public function execute(Household $household, ShoppingList $list, User $actor): int
    {
        $deletedCount = DB::transaction(function () use ($list): int {
            $query = $list->items()->where('is_checked', true);
            $count = (clone $query)->count();

            if ($count > 0) {
                $query->delete();
            }

            return $count;
        });

        if ($deletedCount > 0) {
            $this->realtimePublisher->publishHousehold(
                householdId: (int) $household->id,
                module: 'shopping_list',
                type: 'items.cleared',
                payload: [
                    'list_id' => (int) $list->id,
                    'deleted_count' => $deletedCount,
                    'actor_user_id' => (int) $actor->id,
                ],
            );
        }

        return $deletedCount;
    }
}
