<?php

namespace App\Http\Requests\ShoppingList;

use App\Models\ShoppingListItem;
use App\Models\User;

class RemoveShoppingListItemRequest extends ShoppingListContextRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (!$user instanceof User) {
            return false;
        }

        $household = $this->household();
        $this->ensureItemBelongsToHousehold($this->route('item') instanceof ShoppingListItem ? $this->route('item') : null, $household);
        $this->ensureParentRole();

        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [];
    }
}
