<?php

namespace Tests\Feature\Api;

use App\Models\Event;
use App\Models\Household;
use App\Models\HouseholdSetting;
use App\Models\MealPlan;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CalendarApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_parent_can_read_calendar_board_with_permissions_and_custom_meal_plan(): void
    {
        [$parent, $household] = $this->createHouseholdMember(User::ROLE_PARENT);

        HouseholdSetting::query()->create([
            'household_id' => $household->id,
            'has_calendar' => true,
            'has_tasks' => true,
            'calendar_config' => [
                'shared_view_enabled' => true,
                'absence_tracking_enabled' => true,
            ],
        ]);

        $event = Event::query()->create([
            'household_id' => $household->id,
            'created_by_user_id' => $parent->id,
            'title' => 'Dentiste',
            'start_at' => '2026-03-03 15:00:00',
            'end_at' => '2026-03-03 16:00:00',
            'is_shared_with_other_household' => true,
        ]);

        $mealPlan = MealPlan::query()->create([
            'household_id' => $household->id,
            'date' => '2026-03-03',
            'meal_type' => 'soir',
            'custom_title' => 'Resto',
            'note' => 'Reservation 19h30',
        ]);

        Sanctum::actingAs($parent);

        $response = $this->getJson('/api/calendar/board?from=2026-03-01&to=2026-03-07');

        $response
            ->assertOk()
            ->assertJsonPath('permissions.can_manage_meal_plan', true)
            ->assertJsonPath('events.0.id', $event->id)
            ->assertJsonPath('events.0.permissions.can_update', true)
            ->assertJsonPath('events.0.permissions.can_delete', true)
            ->assertJsonPath('meal_plan.0.id', $mealPlan->id)
            ->assertJsonPath('meal_plan.0.custom_title', 'Resto');
    }

    public function test_parent_can_replace_a_meal_plan_recipe_with_a_custom_title(): void
    {
        [$parent, $household] = $this->createHouseholdMember(User::ROLE_PARENT);

        HouseholdSetting::query()->create([
            'household_id' => $household->id,
            'has_calendar' => true,
            'calendar_config' => [
                'shared_view_enabled' => true,
            ],
        ]);

        $recipe = Recipe::query()->create([
            'household_id' => $household->id,
            'title' => 'Pates bolo',
            'type' => 'plat principal',
            'description' => 'Test',
            'instructions' => 'Cuire',
            'base_servings' => 4,
        ]);

        $mealPlan = MealPlan::query()->create([
            'household_id' => $household->id,
            'date' => '2026-03-04',
            'meal_type' => 'soir',
            'note' => 'Initial',
        ]);

        $mealPlan->items()->create([
            'recipe_id' => $recipe->id,
            'servings' => 4,
            'position' => 1,
        ]);

        Sanctum::actingAs($parent);

        $response = $this->patchJson("/api/calendar/meal-plan/{$mealPlan->id}", [
            'date' => '2026-03-04',
            'meal_type' => 'soir',
            'custom_title' => 'Resto',
            'servings' => 2,
            'note' => 'Sortie en ville',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('meal_plan.custom_title', 'Resto')
            ->assertJsonCount(0, 'meal_plan.recipes');

        $this->assertDatabaseHas('meal_plans', [
            'id' => $mealPlan->id,
            'custom_title' => 'Resto',
            'note' => 'Sortie en ville',
        ]);

        $this->assertSame(
            0,
            $mealPlan->items()->count(),
            'Les anciennes recettes du meal plan doivent etre supprimees.'
        );
    }

    public function test_parent_can_create_meal_plan_without_poll_and_update_same_slot(): void
    {
        [$parent, $household] = $this->createHouseholdMember(User::ROLE_PARENT);

        HouseholdSetting::query()->create([
            'household_id' => $household->id,
            'has_calendar' => true,
        ]);

        $recipe = Recipe::query()->create([
            'household_id' => $household->id,
            'title' => 'Boulettes sauce tomate',
            'type' => 'plat principal',
            'description' => 'Test',
            'instructions' => 'Cuire',
            'base_servings' => 4,
        ]);

        Sanctum::actingAs($parent);

        $createResponse = $this->postJson('/api/calendar/meal-plan', [
            'date' => '2026-03-10',
            'meal_type' => 'midi',
            'recipe_id' => $recipe->id,
            'servings' => 5,
            'note' => 'Creation manuelle',
        ]);

        $mealPlanId = (int) ($createResponse->json('meal_plan.id') ?? 0);

        $createResponse
            ->assertCreated()
            ->assertJsonPath('meal_plan.date', '2026-03-10')
            ->assertJsonPath('meal_plan.meal_type', 'midi');

        $this->assertDatabaseHas('meal_plans', [
            'id' => $mealPlanId,
            'household_id' => $household->id,
            'date' => '2026-03-10',
            'meal_type' => 'midi',
            'note' => 'Creation manuelle',
        ]);

        $updateSameSlotResponse = $this->postJson('/api/calendar/meal-plan', [
            'date' => '2026-03-10',
            'meal_type' => 'midi',
            'custom_title' => 'Repas libre maison',
            'note' => 'Sans recette',
        ]);

        $updateSameSlotResponse
            ->assertOk()
            ->assertJsonPath('meal_plan.id', $mealPlanId)
            ->assertJsonPath('meal_plan.custom_title', 'Repas libre maison')
            ->assertJsonCount(0, 'meal_plan.recipes');
    }

    public function test_child_cannot_update_a_meal_plan(): void
    {
        [$child, $household] = $this->createHouseholdMember(User::ROLE_CHILD);

        HouseholdSetting::query()->create([
            'household_id' => $household->id,
            'has_calendar' => true,
        ]);

        $recipe = Recipe::query()->create([
            'household_id' => $household->id,
            'title' => 'Wraps',
            'type' => 'plat principal',
            'description' => 'Test',
            'instructions' => 'Assembler',
            'base_servings' => 4,
        ]);

        $mealPlan = MealPlan::query()->create([
            'household_id' => $household->id,
            'date' => '2026-03-05',
            'meal_type' => 'midi',
        ]);

        $mealPlan->items()->create([
            'recipe_id' => $recipe->id,
            'servings' => 4,
            'position' => 1,
        ]);

        Sanctum::actingAs($child);

        $this->patchJson("/api/calendar/meal-plan/{$mealPlan->id}", [
            'date' => '2026-03-05',
            'meal_type' => 'midi',
            'custom_title' => 'Resto',
        ])->assertForbidden();
    }

    public function test_calendar_board_does_not_leak_data_from_other_households(): void
    {
        [$parentA, $householdA] = $this->createHouseholdMember(User::ROLE_PARENT);
        [$parentB, $householdB] = $this->createHouseholdMember(User::ROLE_PARENT);

        HouseholdSetting::query()->create([
            'household_id' => $householdA->id,
            'has_calendar' => true,
            'has_tasks' => true,
        ]);

        HouseholdSetting::query()->create([
            'household_id' => $householdB->id,
            'has_calendar' => true,
            'has_tasks' => true,
        ]);

        $eventA = Event::query()->create([
            'household_id' => $householdA->id,
            'created_by_user_id' => $parentA->id,
            'title' => 'Evenement A',
            'start_at' => '2026-03-06 10:00:00',
            'end_at' => '2026-03-06 11:00:00',
            'is_shared_with_other_household' => false,
        ]);

        Event::query()->create([
            'household_id' => $householdB->id,
            'created_by_user_id' => $parentB->id,
            'title' => 'Evenement B',
            'start_at' => '2026-03-06 12:00:00',
            'end_at' => '2026-03-06 13:00:00',
            'is_shared_with_other_household' => false,
        ]);

        $mealPlanA = MealPlan::query()->create([
            'household_id' => $householdA->id,
            'date' => '2026-03-06',
            'meal_type' => 'soir',
            'custom_title' => 'Repas A',
        ]);

        MealPlan::query()->create([
            'household_id' => $householdB->id,
            'date' => '2026-03-06',
            'meal_type' => 'soir',
            'custom_title' => 'Repas B',
        ]);

        Sanctum::actingAs($parentA);

        $response = $this->getJson('/api/calendar/board?from=2026-03-01&to=2026-03-07');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'events')
            ->assertJsonPath('events.0.id', $eventA->id)
            ->assertJsonCount(1, 'meal_plan')
            ->assertJsonPath('meal_plan.0.id', $mealPlanA->id);
    }

    public function test_parent_cannot_update_meal_plan_from_another_household(): void
    {
        [$parentA, $householdA] = $this->createHouseholdMember(User::ROLE_PARENT);
        [$parentB, $householdB] = $this->createHouseholdMember(User::ROLE_PARENT);

        HouseholdSetting::query()->create([
            'household_id' => $householdA->id,
            'has_calendar' => true,
        ]);

        HouseholdSetting::query()->create([
            'household_id' => $householdB->id,
            'has_calendar' => true,
        ]);

        $mealPlan = MealPlan::query()->create([
            'household_id' => $householdA->id,
            'date' => '2026-03-06',
            'meal_type' => 'midi',
            'custom_title' => 'Avant',
            'note' => 'Initial',
        ]);

        Sanctum::actingAs($parentB);

        $this->patchJson("/api/calendar/meal-plan/{$mealPlan->id}", [
            'date' => '2026-03-06',
            'meal_type' => 'midi',
            'custom_title' => 'Tentative',
            'note' => 'Ne doit pas passer',
        ])->assertNotFound();

        $this->assertDatabaseHas('meal_plans', [
            'id' => $mealPlan->id,
            'custom_title' => 'Avant',
            'note' => 'Initial',
        ]);
    }

    public function test_parent_can_create_meal_plan_with_saved_global_recipe(): void
    {
        [$parent, $household] = $this->createHouseholdMember(User::ROLE_PARENT);

        HouseholdSetting::query()->create([
            'household_id' => $household->id,
            'has_calendar' => true,
        ]);

        $globalRecipe = Recipe::query()->create([
            'household_id' => null,
            'is_global' => true,
            'title' => 'Curry global',
            'type' => 'plat principal',
            'description' => 'Test',
            'instructions' => 'Cuire',
            'base_servings' => 4,
        ]);

        $household->savedRecipes()->attach($globalRecipe->id, [
            'added_by_user_id' => $parent->id,
        ]);

        Sanctum::actingAs($parent);

        $response = $this->postJson('/api/calendar/meal-plan', [
            'date' => '2026-03-18',
            'meal_type' => 'soir',
            'recipe_id' => $globalRecipe->id,
            'servings' => 4,
            'note' => 'Avec recette globale',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('meal_plan.date', '2026-03-18')
            ->assertJsonPath('meal_plan.recipes.0.id', $globalRecipe->id);
    }

    private function createHouseholdMember(string $role): array
    {
        $user = User::factory()->create([
            'must_change_password' => false,
        ]);

        $household = Household::query()->create([
            'name' => 'Foyer test',
        ]);

        $household->users()->attach($user->id, [
            'role' => $role,
        ]);

        return [$user->fresh(), $household];
    }
}
