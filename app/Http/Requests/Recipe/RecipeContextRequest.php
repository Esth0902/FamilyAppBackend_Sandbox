<?php

namespace App\Http\Requests\Recipe;

use App\Http\Controllers\Api\Concerns\ResolvesHouseholdContext;
use App\Models\Household;
use Illuminate\Foundation\Http\FormRequest;

abstract class RecipeContextRequest extends FormRequest
{
    use ResolvesHouseholdContext;

    protected const RECIPE_TYPES = [
        'petit-déjeuner',
        'entrée',
        'plat principal',
        'dessert',
        'collation',
        'boisson',
        'autre',
    ];

    protected const INGREDIENT_CATEGORIES = [
        'fruits et légumes',
        'boucherie',
        'poissonnerie',
        'crèmerie',
        'épicerie salée',
        'épicerie sucrée',
        'boissons',
        'surgelés',
        'entretien et hygiène',
        'autre',
    ];

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
