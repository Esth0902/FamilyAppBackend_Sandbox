<?php

namespace App\Actions\ShoppingList;

use App\Models\Household;
use App\Models\ShoppingList;
use App\Models\User;
use App\Services\RealtimePublisher;

class DestroyShoppingListAction
{
    public function __construct(private readonly RealtimePublisher $realtimePublisher)
    {
    }

    public function execute(Household $household, User $actor, ShoppingList $list): void
    {
        $listId = (int) $list->id;
        $title = (string) $list->title;
        $list->delete();

        $this->realtimePublisher->publishHousehold(
            householdId: (int) $household->id,
            module: 'shopping_list',
            type: 'list.deleted',
            payload: [
                'list_id' => $listId,
                'title' => $title,
                'actor_user_id' => (int) $actor->id,
            ],
        );
    }
}
