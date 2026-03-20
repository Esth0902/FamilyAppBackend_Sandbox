<?php

namespace App\Http\Requests\ShoppingList;

use App\Models\ShoppingListItem;
use App\Models\User;

class UpdateShoppingListItemRequest extends ShoppingListContextRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (!$user instanceof User) {
            return false;
        }

        $household = $this->household();
        $this->ensureItemBelongsToHousehold($this->route('item') instanceof ShoppingListItem ? $this->route('item') : null, $household);
        $this->ensureCanModifyItemsRole();

        return true;
    }

    protected function prepareForValidation(): void
    {
        $payload = [];

        if ($this->exists('name')) {
            $name = $this->input('name');
            $payload['name'] = is_string($name) ? trim($name) : $name;
        }

        if ($this->exists('quantity')) {
            $quantity = $this->input('quantity');
            $payload['quantity'] = is_string($quantity) ? trim($quantity) : $quantity;
        }

        if (count($payload) > 0) {
            $this->merge($payload);
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'is_checked' => ['sometimes', 'boolean'],
            'quantity' => ['sometimes', 'nullable', 'string', 'max:50'],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
