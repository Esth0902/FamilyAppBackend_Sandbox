<?php

namespace App\Http\Requests\ShoppingList;

use App\Models\User;

class StoreShoppingListRequest extends ShoppingListContextRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (!$user instanceof User) {
            return false;
        }

        $this->ensureShoppingModuleEnabled($this->household());
        $this->ensureParentRole();

        return true;
    }

    protected function prepareForValidation(): void
    {
        $title = $this->input('title');
        if (!is_string($title)) {
            return;
        }

        $this->merge([
            'title' => trim($title),
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Le nom de la liste est obligatoire.',
        ];
    }
}
