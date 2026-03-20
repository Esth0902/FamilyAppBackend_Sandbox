<?php

namespace App\Http\Requests\ShoppingList;

use App\Models\ShoppingList;
use App\Models\User;

class GetShoppingListRequest extends ShoppingListContextRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (!$user instanceof User) {
            return false;
        }

        $household = $this->household();
        $this->ensureShoppingModuleEnabled($household);

        $list = $this->route('list');
        if ($list !== null) {
            $this->ensureListBelongsToHousehold($list instanceof ShoppingList ? $list : null, $household);
        }

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
