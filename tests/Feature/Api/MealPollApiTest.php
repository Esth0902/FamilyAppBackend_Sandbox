<?php

namespace Tests\Feature\Api;

use App\Models\Household;
use App\Models\MealPoll;
use App\Models\MealPollOption;
use App\Models\MealPollVote;
use App\Models\Recipe;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MealPollApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_closing_poll_notifies_members_who_did_not_vote_with_results_hint(): void
    {
        $parent = User::factory()->create([
            'must_change_password' => false,
        ]);
        $child = User::factory()->create([
            'must_change_password' => false,
        ]);

        $household = Household::query()->create([
            'name' => 'Foyer test clôture sondage',
        ]);
        $household->users()->attach($parent->id, [
            'role' => User::ROLE_PARENT,
        ]);
        $household->users()->attach($child->id, [
            'role' => User::ROLE_CHILD,
        ]);

        $recipeA = Recipe::query()->create([
            'household_id' => $household->id,
            'title' => 'Recette A',
            'type' => 'plat principal',
        ]);
        $recipeB = Recipe::query()->create([
            'household_id' => $household->id,
            'title' => 'Recette B',
            'type' => 'plat principal',
        ]);

        $poll = MealPoll::query()->create([
            'household_id' => $household->id,
            'title' => 'Sondage test clôture',
            'created_by_user_id' => $parent->id,
            'starts_at' => now()->subHours(1),
            'ends_at' => now()->addHours(2),
            'planning_start_date' => '2026-03-20',
            'planning_end_date' => '2026-03-26',
            'status' => 'open',
            'max_votes_per_user' => 1,
        ]);

        $optionA = MealPollOption::query()->create([
            'meal_poll_id' => $poll->id,
            'recipe_id' => $recipeA->id,
        ]);
        MealPollOption::query()->create([
            'meal_poll_id' => $poll->id,
            'recipe_id' => $recipeB->id,
        ]);

        MealPollVote::query()->create([
            'meal_poll_id' => $poll->id,
            'user_id' => $parent->id,
            'meal_poll_option_id' => $optionA->id,
        ]);

        Sanctum::actingAs($parent);

        $this->postJson("/api/meal-polls/{$poll->id}/close")
            ->assertOk()
            ->assertJsonPath('poll.status', 'closed');

        $this->assertDatabaseHas('user_notifications', [
            'household_id' => $household->id,
            'user_id' => $child->id,
            'type' => 'poll_closed_too_late',
        ]);

        $this->assertDatabaseMissing('user_notifications', [
            'household_id' => $household->id,
            'user_id' => $parent->id,
            'type' => 'poll_closed_too_late',
        ]);

        /** @var UserNotification $notification */
        $notification = UserNotification::query()
            ->where('household_id', $household->id)
            ->where('user_id', $child->id)
            ->where('type', 'poll_closed_too_late')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame((int) $poll->id, (int) data_get($notification->data, 'poll_id'));
    }

    public function test_parent_can_update_open_poll_parameters_and_recipes(): void
    {
        $parent = User::factory()->create([
            'must_change_password' => false,
        ]);

        $household = Household::query()->create([
            'name' => 'Foyer test sondage',
        ]);
        $household->users()->attach($parent->id, [
            'role' => User::ROLE_PARENT,
        ]);

        $recipeA = Recipe::query()->create([
            'household_id' => $household->id,
            'title' => 'Recette A',
            'type' => 'plat principal',
        ]);
        $recipeB = Recipe::query()->create([
            'household_id' => $household->id,
            'title' => 'Recette B',
            'type' => 'plat principal',
        ]);
        $recipeC = Recipe::query()->create([
            'household_id' => $household->id,
            'title' => 'Recette C',
            'type' => 'plat principal',
        ]);

        $poll = MealPoll::query()->create([
            'household_id' => $household->id,
            'title' => 'Sondage initial',
            'created_by_user_id' => $parent->id,
            'starts_at' => now(),
            'ends_at' => now()->addHours(24),
            'planning_start_date' => '2026-03-16',
            'planning_end_date' => '2026-03-22',
            'status' => 'open',
            'max_votes_per_user' => 2,
        ]);

        $optionA = MealPollOption::query()->create([
            'meal_poll_id' => $poll->id,
            'recipe_id' => $recipeA->id,
        ]);
        $optionB = MealPollOption::query()->create([
            'meal_poll_id' => $poll->id,
            'recipe_id' => $recipeB->id,
        ]);

        MealPollVote::query()->create([
            'meal_poll_id' => $poll->id,
            'user_id' => $parent->id,
            'meal_poll_option_id' => $optionB->id,
        ]);

        Sanctum::actingAs($parent);

        $response = $this->patchJson("/api/meal-polls/{$poll->id}", [
            'title' => 'Sondage corrigé',
            'recipe_ids' => [$recipeA->id, $recipeC->id],
            'duration_hours' => 48,
            'max_votes_per_user' => 2,
            'planning_start_date' => '2026-03-17',
            'planning_end_date' => '2026-03-23',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('poll.id', $poll->id)
            ->assertJsonPath('poll.title', 'Sondage corrigé')
            ->assertJsonPath('poll.max_votes_per_user', 2)
            ->assertJsonPath('poll.planning_start_date', '2026-03-17')
            ->assertJsonPath('poll.planning_end_date', '2026-03-23');

        $this->assertDatabaseHas('meal_poll_options', [
            'meal_poll_id' => $poll->id,
            'recipe_id' => $recipeA->id,
        ]);
        $this->assertDatabaseHas('meal_poll_options', [
            'meal_poll_id' => $poll->id,
            'recipe_id' => $recipeC->id,
        ]);
        $this->assertDatabaseMissing('meal_poll_options', [
            'meal_poll_id' => $poll->id,
            'recipe_id' => $recipeB->id,
        ]);
        $this->assertDatabaseMissing('meal_poll_votes', [
            'meal_poll_id' => $poll->id,
            'meal_poll_option_id' => $optionB->id,
        ]);
    }

    public function test_parent_cannot_update_closed_poll(): void
    {
        $parent = User::factory()->create([
            'must_change_password' => false,
        ]);

        $household = Household::query()->create([
            'name' => 'Foyer test sondage fermé',
        ]);
        $household->users()->attach($parent->id, [
            'role' => User::ROLE_PARENT,
        ]);

        $recipeA = Recipe::query()->create([
            'household_id' => $household->id,
            'title' => 'Recette A',
            'type' => 'plat principal',
        ]);
        $recipeB = Recipe::query()->create([
            'household_id' => $household->id,
            'title' => 'Recette B',
            'type' => 'plat principal',
        ]);

        $poll = MealPoll::query()->create([
            'household_id' => $household->id,
            'title' => 'Sondage fermé',
            'created_by_user_id' => $parent->id,
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subDay(),
            'planning_start_date' => '2026-03-10',
            'planning_end_date' => '2026-03-16',
            'status' => 'closed',
            'max_votes_per_user' => 2,
        ]);

        MealPollOption::query()->create([
            'meal_poll_id' => $poll->id,
            'recipe_id' => $recipeA->id,
        ]);
        MealPollOption::query()->create([
            'meal_poll_id' => $poll->id,
            'recipe_id' => $recipeB->id,
        ]);

        Sanctum::actingAs($parent);

        $this->patchJson("/api/meal-polls/{$poll->id}", [
            'title' => 'Tentative de correction',
            'recipe_ids' => [$recipeA->id, $recipeB->id],
            'duration_hours' => 24,
            'max_votes_per_user' => 2,
            'planning_start_date' => '2026-03-10',
            'planning_end_date' => '2026-03-16',
        ])->assertStatus(422);
    }

    public function test_parent_can_create_poll_with_saved_global_recipe(): void
    {
        $parent = User::factory()->create([
            'must_change_password' => false,
        ]);

        $household = Household::query()->create([
            'name' => 'Foyer test global',
        ]);
        $household->users()->attach($parent->id, [
            'role' => User::ROLE_PARENT,
        ]);

        $localRecipe = Recipe::query()->create([
            'household_id' => $household->id,
            'title' => 'Recette locale',
            'type' => 'plat principal',
        ]);

        $globalRecipe = Recipe::query()->create([
            'household_id' => null,
            'is_global' => true,
            'title' => 'Recette globale',
            'type' => 'plat principal',
        ]);

        $household->savedRecipes()->attach($globalRecipe->id, [
            'added_by_user_id' => $parent->id,
        ]);

        Sanctum::actingAs($parent);

        $response = $this->postJson('/api/meal-polls', [
            'title' => 'Sondage mixte',
            'recipe_ids' => [$localRecipe->id, $globalRecipe->id],
            'planning_start_date' => '2026-03-24',
            'planning_end_date' => '2026-03-30',
            'duration_hours' => 24,
            'max_votes_per_user' => 2,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('poll.title', 'Sondage mixte');

        $pollId = (int)($response->json('poll.id') ?? 0);
        $this->assertGreaterThan(0, $pollId);

        $this->assertDatabaseHas('meal_poll_options', [
            'meal_poll_id' => $pollId,
            'recipe_id' => $globalRecipe->id,
        ]);
    }
}
