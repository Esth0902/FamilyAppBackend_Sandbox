<?php

namespace App\Actions\ShoppingList;

use App\Models\Household;
use App\Models\ShoppingList;
use App\Models\User;
use Illuminate\Support\Collection;

class GetShoppingListsAction
{
    /**
     * @return array{can_manage: bool, lists: Collection<int, ShoppingList>}
     */
    public function execute(Household $household, string $role): array
    {
        $lists = ShoppingList::query()
            ->where('household_id', $household->id)
            ->withCount('items')
            ->orderByDesc('created_at')
            ->get();

        return [
            'can_manage' => $role === User::ROLE_PARENT,
            'lists' => $lists,
        ];
    }
}
