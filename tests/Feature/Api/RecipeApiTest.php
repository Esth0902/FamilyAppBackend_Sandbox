<?php

namespace Tests\Feature\Api;

use App\Models\Household;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RecipeApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_scope_all_contains_global_and_household_recipes_only(): void
    {
        [$parentA, $householdA] = $this->createHouseholdMember(User::ROLE_PARENT, 'Foyer A');
        [$parentB, $householdB] = $this->createHouseholdMember(User::ROLE_PARENT, 'Foyer B');

        $recipeA = Recipe::query()->create([
            'household_id' => $householdA->id,
            'title' => 'Recette foyer A',
            'type' => 'plat principal',
        ]);

        Recipe::query()->create([
            'household_id' => $householdB->id,
            'title' => 'Recette foyer B',
            'type' => 'plat principal',
        ]);

        $globalRecipe = Recipe::query()->create([
            'household_id' => null,
            'is_global' => true,
            'title' => 'Recette globale test',
            'type' => 'plat principal',
        ]);

        Sanctum::actingAs($parentA);

        $response = $this->getJson('/api/recipes?scope=all');

        $response
            ->assertOk()
            ->assertJsonFragment(['id' => $recipeA->id, 'title' => 'Recette foyer A'])
            ->assertJsonFragment(['id' => $globalRecipe->id, 'title' => 'Recette globale test'])
            ->assertJsonMissing(['title' => 'Recette foyer B']);
    }

    public function test_household_member_can_add_and_remove_global_recipe_from_mine(): void
    {
        [$child, $household] = $this->createHouseholdMember(User::ROLE_CHILD, 'Foyer enfant');

        $globalRecipe = Recipe::query()->create([
            'household_id' => null,
            'is_global' => true,
            'title' => 'Risotto global',
            'type' => 'plat principal',
        ]);

        Sanctum::actingAs($child);

        $this->postJson("/api/recipes/{$globalRecipe->id}/save")
            ->assertOk()
            ->assertJsonPath('recipe.id', $globalRecipe->id)
            ->assertJsonPath('recipe.is_in_my_recipes', true);

        $mineResponse = $this->getJson('/api/recipes?scope=mine');
        $mineResponse
            ->assertOk()
            ->assertJsonFragment(['id' => $globalRecipe->id, 'title' => 'Risotto global']);

        $this->deleteJson("/api/recipes/{$globalRecipe->id}/save")
            ->assertOk()
            ->assertJsonPath('recipe.id', $globalRecipe->id)
            ->assertJsonPath('recipe.is_in_my_recipes', false);

        $mineAfterDelete = $this->getJson('/api/recipes?scope=mine');
        $mineAfterDelete
            ->assertOk()
            ->assertJsonMissing(['id' => $globalRecipe->id, 'title' => 'Risotto global']);

        $this->assertDatabaseMissing('household_recipe_bookmarks', [
            'household_id' => $household->id,
            'recipe_id' => $globalRecipe->id,
        ]);
    }

    public function test_cannot_save_non_global_recipe(): void
    {
        [$parent, $household] = $this->createHouseholdMember(User::ROLE_PARENT, 'Foyer parent');

        $recipe = Recipe::query()->create([
            'household_id' => $household->id,
            'title' => 'Recette locale',
            'type' => 'plat principal',
        ]);

        Sanctum::actingAs($parent);

        $this->postJson("/api/recipes/{$recipe->id}/save")
            ->assertStatus(422);
    }

    private function createHouseholdMember(string $role, string $householdName): array
    {
        $user = User::factory()->create([
            'must_change_password' => false,
        ]);

        $household = Household::query()->create([
            'name' => $householdName,
        ]);

        $household->users()->attach($user->id, [
            'role' => $role,
        ]);

        return [$user->fresh(), $household];
    }
}
