<?php

namespace App\Actions\ShoppingList;

use App\Models\Household;
use App\Models\ShoppingList;
use App\Models\User;
use App\Services\RealtimePublisher;

class StoreShoppingListAction
{
    public function __construct(private readonly RealtimePublisher $realtimePublisher)
    {
    }

    public function execute(Household $household, User $actor, string $title): ShoppingList
    {
        $list = ShoppingList::query()->create([
            'household_id' => $household->id,
            'title' => $title,
            'status' => 'active',
        ]);

        $this->realtimePublisher->publishHousehold(
            householdId: (int) $household->id,
            module: 'shopping_list',
            type: 'list.created',
            payload: [
                'list_id' => (int) $list->id,
                'title' => (string) $list->title,
                'actor_user_id' => (int) $actor->id,
            ],
        );

        return $list->loadCount('items');
    }
}
