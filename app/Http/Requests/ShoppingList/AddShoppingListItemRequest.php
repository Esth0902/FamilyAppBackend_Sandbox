<?php

namespace App\Http\Requests\ShoppingList;

use App\Models\ShoppingList;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\Validator;

class AddShoppingListItemRequest extends ShoppingListContextRequest
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
        $this->ensureCanModifyItemsRole();

        if ($this->householdRole() === User::ROLE_CHILD && !$this->isManualAddition()) {
            throw new AuthorizationException('Un enfant peut uniquement ajouter un élément manuel.');
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        $payload = [];

        if ($this->exists('name')) {
            $name = $this->input('name');
            $payload['name'] = is_string($name) ? trim($name) : $name;
        }

        if ($this->exists('unit')) {
            $unit = $this->input('unit');
            $payload['unit'] = is_string($unit) ? trim($unit) : $unit;
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
            'ingredient_id' => ['nullable', 'integer', 'exists:ingredients,id'],
            'name' => ['nullable', 'string', 'max:255'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'unit' => ['nullable', 'string', 'max:50'],
            'is_manual_addition' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $ingredientId = $this->input('ingredient_id');
            $name = trim((string) $this->input('name', ''));

            if (($ingredientId === null || $ingredientId === '') && $name === '') {
                $validator->errors()->add('name', 'Le nom est obligatoire si aucun ingrédient n est fourni.');
            }
        });
    }

    public function isManualAddition(): bool
    {
        if (!$this->exists('is_manual_addition')) {
            return true;
        }

        return $this->boolean('is_manual_addition');
    }
}
