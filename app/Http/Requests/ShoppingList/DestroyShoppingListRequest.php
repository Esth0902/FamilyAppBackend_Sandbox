<?php

namespace App\Http\Requests\ShoppingList;

use App\Models\ShoppingList;
use App\Models\User;

class DestroyShoppingListRequest extends ShoppingListContextRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (!$user instanceof User) {
            return false;
        }

        $household = $this->household();
        $this->ensureShoppingModuleEnabled($household);
        $this->ensureListBelongsToHousehold($this->route('list') instanceof ShoppingList ? $this->route('list') : null, $household);
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
