<?php

namespace App\Http\Requests\ShoppingList;

use App\Http\Controllers\Api\Concerns\ResolvesHouseholdContext;
use App\Models\Household;
use App\Models\MealSetting;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

abstract class ShoppingListContextRequest extends FormRequest
{
    use ResolvesHouseholdContext;

    private ?Household $resolvedHousehold = null;

    private ?string $resolvedHouseholdRole = null;

    public function household(): Household
    {
        $this->resolveContext();

        return $this->resolvedHousehold;
    }

    public function householdRole(): string
    {
        $this->resolveContext();

        return (string) $this->resolvedHouseholdRole;
    }

    protected function ensureShoppingModuleEnabled(Household $household): void
    {
        $mealSettings = MealSetting::query()
            ->where('household_id', $household->id)
            ->first();

        if ($mealSettings && !(bool) $mealSettings->enable_shopping_list) {
            throw new AuthorizationException('Le module liste de courses est désactivé pour ce foyer.');
        }
    }

    protected function ensureListBelongsToHousehold(?ShoppingList $list, Household $household): void
    {
        if (!$list instanceof ShoppingList || (int) $list->household_id !== (int) $household->id) {
            throw new NotFoundHttpException('Liste introuvable.');
        }
    }

    protected function ensureItemBelongsToHousehold(?ShoppingListItem $item, Household $household): void
    {
        if (!$item instanceof ShoppingListItem) {
            throw new NotFoundHttpException('Élément introuvable.');
        }

        $list = $item->shoppingList()->first();
        if (!$list || (int) $list->household_id !== (int) $household->id) {
            throw new NotFoundHttpException('Élément introuvable.');
        }
    }

    protected function ensureParentRole(): void
    {
        if ($this->householdRole() !== User::ROLE_PARENT) {
            throw new AuthorizationException('Action réservée aux parents.');
        }
    }

    protected function ensureCanModifyItemsRole(): void
    {
        if (!in_array($this->householdRole(), [User::ROLE_PARENT, User::ROLE_CHILD], true)) {
            throw new AuthorizationException('Rôle non autorisé pour modifier la liste de courses.');
        }
    }

    private function resolveContext(): void
    {
        if ($this->resolvedHousehold instanceof Household && is_string($this->resolvedHouseholdRole)) {
            return;
        }

        [$household, $role] = $this->resolveHouseholdWithRole($this);

        $this->resolvedHousehold = $household;
        $this->resolvedHouseholdRole = $role;
    }
}
