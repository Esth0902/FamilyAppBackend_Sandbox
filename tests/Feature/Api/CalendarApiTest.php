<?php

namespace Tests\Feature\Api;

use App\Models\Event;
use App\Models\Household;
use App\Models\HouseholdSetting;
use App\Models\MealPlan;
use App\Models\Recipe;
use App\Models\User;
use App\Models\UserNotification;
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

    public function test_linked_household_can_see_only_shared_events_from_other_household(): void
    {
        [$parentA, $householdA] = $this->createHouseholdMember(User::ROLE_PARENT);
        [$parentB, $householdB] = $this->createHouseholdMember(User::ROLE_PARENT);

        HouseholdSetting::query()->create([
            'household_id' => $householdA->id,
            'has_calendar' => true,
            'calendar_config' => [
                'shared_view_enabled' => true,
            ],
        ]);

        HouseholdSetting::query()->create([
            'household_id' => $householdB->id,
            'has_calendar' => true,
            'calendar_config' => [
                'shared_view_enabled' => true,
            ],
        ]);

        $householdA->forceFill(['linked_household_id' => $householdB->id])->save();
        $householdB->forceFill(['linked_household_id' => $householdA->id])->save();

        $sharedEventFromA = Event::query()->create([
            'household_id' => $householdA->id,
            'created_by_user_id' => $parentA->id,
            'title' => 'Evenement partage A',
            'start_at' => '2026-03-06 09:00:00',
            'end_at' => '2026-03-06 10:00:00',
            'is_shared_with_other_household' => true,
        ]);

        Event::query()->create([
            'household_id' => $householdA->id,
            'created_by_user_id' => $parentA->id,
            'title' => 'Evenement prive A',
            'start_at' => '2026-03-06 11:00:00',
            'end_at' => '2026-03-06 12:00:00',
            'is_shared_with_other_household' => false,
        ]);

        $eventFromB = Event::query()->create([
            'household_id' => $householdB->id,
            'created_by_user_id' => $parentB->id,
            'title' => 'Evenement B',
            'start_at' => '2026-03-06 14:00:00',
            'end_at' => '2026-03-06 15:00:00',
            'is_shared_with_other_household' => false,
        ]);

        Sanctum::actingAs($parentB);

        $response = $this->getJson('/api/calendar/board?from=2026-03-01&to=2026-03-07');
        $response->assertOk();

        /** @var array<int, array<string, mixed>> $events */
        $events = $response->json('events') ?? [];

        $this->assertTrue(
            collect($events)->contains(fn(array $event): bool => (int) ($event['id'] ?? 0) === (int) $sharedEventFromA->id)
        );
        $this->assertFalse(
            collect($events)->contains(fn(array $event): bool => (string) ($event['title'] ?? '') === 'Evenement prive A')
        );
        $this->assertTrue(
            collect($events)->contains(fn(array $event): bool => (int) ($event['id'] ?? 0) === (int) $eventFromB->id)
        );

        $sharedEventPayload = collect($events)->first(
            fn(array $event): bool => (int) ($event['id'] ?? 0) === (int) $sharedEventFromA->id
        );

        $this->assertIsArray($sharedEventPayload);
        $this->assertSame((int) $householdA->id, (int) ($sharedEventPayload['source_household_id'] ?? 0));
        $this->assertFalse((bool) (($sharedEventPayload['permissions']['can_update'] ?? true)));
        $this->assertFalse((bool) (($sharedEventPayload['permissions']['can_delete'] ?? true)));
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

    public function test_member_can_confirm_meal_presence_with_optional_reason(): void
    {
        [$member, $household] = $this->createHouseholdMember(User::ROLE_CHILD);

        HouseholdSetting::query()->create([
            'household_id' => $household->id,
            'has_calendar' => true,
            'calendar_config' => [
                'absence_tracking_enabled' => true,
            ],
        ]);

        $mealPlan = MealPlan::query()->create([
            'household_id' => $household->id,
            'date' => '2026-03-20',
            'meal_type' => 'soir',
            'custom_title' => 'Pizza',
        ]);

        Sanctum::actingAs($member);

        $this->postJson("/api/calendar/meal-plan/{$mealPlan->id}/attendance", [
            'status' => 'not_home',
            'reason' => 'Je mange chez un ami',
        ])
            ->assertOk()
            ->assertJsonPath('attendance.status', 'not_home')
            ->assertJsonPath('attendance.reason', 'Je mange chez un ami');

        $this->assertDatabaseHas('meal_plan_attendances', [
            'household_id' => $household->id,
            'meal_plan_id' => $mealPlan->id,
            'user_id' => $member->id,
            'status' => 'not_home',
            'reason' => 'Je mange chez un ami',
        ]);
    }

    public function test_member_can_confirm_event_participation_with_reason(): void
    {
        [$member, $household] = $this->createHouseholdMember(User::ROLE_CHILD);

        HouseholdSetting::query()->create([
            'household_id' => $household->id,
            'has_calendar' => true,
        ]);

        $event = Event::query()->create([
            'household_id' => $household->id,
            'created_by_user_id' => $member->id,
            'title' => 'Anniversaire',
            'start_at' => '2026-03-22 14:00:00',
            'end_at' => '2026-03-22 17:00:00',
            'is_shared_with_other_household' => false,
        ]);

        Sanctum::actingAs($member);

        $this->postJson("/api/calendar/events/{$event->id}/participation", [
            'status' => 'not_participate',
            'reason' => 'Je suis chez mamie',
        ])
            ->assertOk()
            ->assertJsonPath('participation.status', 'not_participate')
            ->assertJsonPath('participation.reason', 'Je suis chez mamie');

        $this->assertDatabaseHas('event_participations', [
            'household_id' => $household->id,
            'event_id' => $event->id,
            'user_id' => $member->id,
            'status' => 'not_participate',
            'reason' => 'Je suis chez mamie',
        ]);
    }

    public function test_board_returns_only_current_member_confirmation_payloads(): void
    {
        [$memberA, $household] = $this->createHouseholdMember(User::ROLE_PARENT);
        $memberB = User::factory()->create([
            'must_change_password' => false,
        ]);
        $household->users()->attach($memberB->id, ['role' => User::ROLE_CHILD]);

        HouseholdSetting::query()->create([
            'household_id' => $household->id,
            'has_calendar' => true,
            'calendar_config' => [
                'absence_tracking_enabled' => true,
            ],
        ]);

        $mealPlan = MealPlan::query()->create([
            'household_id' => $household->id,
            'date' => '2026-03-24',
            'meal_type' => 'soir',
            'custom_title' => 'Pates',
        ]);

        $event = Event::query()->create([
            'household_id' => $household->id,
            'created_by_user_id' => $memberA->id,
            'title' => 'Cinema',
            'start_at' => '2026-03-24 19:00:00',
            'end_at' => '2026-03-24 21:00:00',
            'is_shared_with_other_household' => false,
        ]);

        $this->postJson("/api/calendar/meal-plan/{$mealPlan->id}/attendance", [
            'status' => 'later',
            'reason' => 'Retour tardif',
        ])->assertUnauthorized();

        Sanctum::actingAs($memberA);
        $this->postJson("/api/calendar/meal-plan/{$mealPlan->id}/attendance", [
            'status' => 'later',
            'reason' => 'Retour tardif',
        ])->assertOk();
        $this->postJson("/api/calendar/events/{$event->id}/participation", [
            'status' => 'participate',
        ])->assertOk();

        Sanctum::actingAs($memberB);
        $this->postJson("/api/calendar/meal-plan/{$mealPlan->id}/attendance", [
            'status' => 'not_home',
            'reason' => 'Repas externe',
        ])->assertOk();
        $this->postJson("/api/calendar/events/{$event->id}/participation", [
            'status' => 'not_participate',
            'reason' => 'Je suis absent',
        ])->assertOk();

        $response = $this->getJson('/api/calendar/board?from=2026-03-20&to=2026-03-26');

        $response
            ->assertOk()
            ->assertJsonPath('meal_plan.0.my_presence.status', 'not_home')
            ->assertJsonPath('meal_plan.0.my_presence.reason', 'Repas externe')
            ->assertJsonPath('events.0.my_participation.status', 'not_participate')
            ->assertJsonPath('events.0.my_participation.reason', 'Je suis absent');

        $mealOverview = $response->json('meal_plan.0.presence_overview');
        $this->assertIsArray($mealOverview);
        $this->assertTrue(
            collect($mealOverview['not_home'] ?? [])->contains(
                fn(array $item): bool => (int) ($item['id'] ?? 0) === (int) $memberB->id
            )
        );
        $this->assertTrue(
            collect($mealOverview['later'] ?? [])->contains(
                fn(array $item): bool => (int) ($item['id'] ?? 0) === (int) $memberA->id
            )
        );

        $eventOverview = $response->json('events.0.participation_overview');
        $this->assertIsArray($eventOverview);
        $this->assertTrue(
            collect($eventOverview['participate'] ?? [])->contains(
                fn(array $item): bool => (int) ($item['id'] ?? 0) === (int) $memberA->id
            )
        );
        $this->assertTrue(
            collect($eventOverview['not_participate'] ?? [])->contains(
                fn(array $item): bool => (int) ($item['id'] ?? 0) === (int) $memberB->id
            )
        );
    }

    public function test_parents_are_notified_when_member_reports_meal_absence_or_late_meal(): void
    {
        [$parentA, $household] = $this->createHouseholdMember(User::ROLE_PARENT);
        $parentB = User::factory()->create(['must_change_password' => false]);
        $child = User::factory()->create(['must_change_password' => false]);
        $household->users()->attach($parentB->id, ['role' => User::ROLE_PARENT]);
        $household->users()->attach($child->id, ['role' => User::ROLE_CHILD]);

        HouseholdSetting::query()->create([
            'household_id' => $household->id,
            'has_calendar' => true,
            'calendar_config' => [
                'absence_tracking_enabled' => true,
            ],
        ]);

        $mealPlan = MealPlan::query()->create([
            'household_id' => $household->id,
            'date' => '2026-03-27',
            'meal_type' => 'soir',
            'custom_title' => 'Soupe',
        ]);

        Sanctum::actingAs($child);
        $this->postJson("/api/calendar/meal-plan/{$mealPlan->id}/attendance", [
            'status' => 'not_home',
            'reason' => 'Repas en dehors',
        ])->assertOk();

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $parentA->id,
            'household_id' => $household->id,
            'type' => 'calendar_meal_presence_updated',
        ]);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $parentB->id,
            'household_id' => $household->id,
            'type' => 'calendar_meal_presence_updated',
        ]);

        $countAfterAbsence = UserNotification::query()
            ->where('household_id', $household->id)
            ->where('type', 'calendar_meal_presence_updated')
            ->count();

        $this->postJson("/api/calendar/meal-plan/{$mealPlan->id}/attendance", [
            'status' => 'present',
        ])->assertOk();

        $countAfterPresent = UserNotification::query()
            ->where('household_id', $household->id)
            ->where('type', 'calendar_meal_presence_updated')
            ->count();

        $this->assertSame($countAfterAbsence, $countAfterPresent);
    }

    public function test_parents_are_notified_when_member_confirms_event_participation(): void
    {
        [$parentA, $household] = $this->createHouseholdMember(User::ROLE_PARENT);
        $parentB = User::factory()->create(['must_change_password' => false]);
        $child = User::factory()->create(['must_change_password' => false]);
        $household->users()->attach($parentB->id, ['role' => User::ROLE_PARENT]);
        $household->users()->attach($child->id, ['role' => User::ROLE_CHILD]);

        HouseholdSetting::query()->create([
            'household_id' => $household->id,
            'has_calendar' => true,
        ]);

        $event = Event::query()->create([
            'household_id' => $household->id,
            'created_by_user_id' => $parentA->id,
            'title' => 'Sortie parc',
            'start_at' => '2026-03-28 10:00:00',
            'end_at' => '2026-03-28 12:00:00',
            'is_shared_with_other_household' => false,
        ]);

        Sanctum::actingAs($child);
        $this->postJson("/api/calendar/events/{$event->id}/participation", [
            'status' => 'participate',
        ])->assertOk();

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $parentA->id,
            'household_id' => $household->id,
            'type' => 'calendar_event_participation_updated',
        ]);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $parentB->id,
            'household_id' => $household->id,
            'type' => 'calendar_event_participation_updated',
        ]);
    }

    public function test_calendar_changes_notify_other_household_members(): void
    {
        [$parentA, $household] = $this->createHouseholdMember(User::ROLE_PARENT);
        $parentB = User::factory()->create(['must_change_password' => false]);
        $child = User::factory()->create(['must_change_password' => false]);
        $household->users()->attach($parentB->id, ['role' => User::ROLE_PARENT]);
        $household->users()->attach($child->id, ['role' => User::ROLE_CHILD]);

        HouseholdSetting::query()->create([
            'household_id' => $household->id,
            'has_calendar' => true,
        ]);

        Sanctum::actingAs($parentA);
        $eventCreate = $this->postJson('/api/calendar/events', [
            'title' => 'Médecin',
            'start_at' => '2026-03-29T14:00:00',
            'end_at' => '2026-03-29T14:30:00',
            'is_shared_with_other_household' => false,
        ])->assertCreated();
        $eventId = (int) ($eventCreate->json('event.id') ?? 0);

        $this->patchJson("/api/calendar/events/{$eventId}", [
            'title' => 'Médecin modifié',
            'start_at' => '2026-03-29T15:00:00',
            'end_at' => '2026-03-29T15:30:00',
            'is_shared_with_other_household' => false,
        ])->assertOk();
        $this->deleteJson("/api/calendar/events/{$eventId}")->assertOk();

        $mealCreate = $this->postJson('/api/calendar/meal-plan', [
            'date' => '2026-03-29',
            'meal_type' => 'midi',
            'custom_title' => 'Pâtes',
        ])->assertCreated();
        $mealPlanId = (int) ($mealCreate->json('meal_plan.id') ?? 0);

        $this->patchJson("/api/calendar/meal-plan/{$mealPlanId}", [
            'date' => '2026-03-29',
            'meal_type' => 'midi',
            'custom_title' => 'Pâtes maison',
        ])->assertOk();
        $this->deleteJson("/api/calendar/meal-plan/{$mealPlanId}")->assertOk();

        foreach (['calendar_event_added', 'calendar_event_updated', 'calendar_event_deleted'] as $type) {
            $this->assertDatabaseHas('user_notifications', [
                'user_id' => $parentB->id,
                'household_id' => $household->id,
                'type' => $type,
            ]);
            $this->assertDatabaseHas('user_notifications', [
                'user_id' => $child->id,
                'household_id' => $household->id,
                'type' => $type,
            ]);
        }

        foreach (['calendar_meal_plan_added', 'calendar_meal_plan_updated', 'calendar_meal_plan_deleted'] as $type) {
            $this->assertDatabaseHas('user_notifications', [
                'user_id' => $parentB->id,
                'household_id' => $household->id,
                'type' => $type,
            ]);
            $this->assertDatabaseHas('user_notifications', [
                'user_id' => $child->id,
                'household_id' => $household->id,
                'type' => $type,
            ]);
        }
    }

    public function test_member_cannot_confirm_meal_presence_from_another_household(): void
    {
        [$memberA, $householdA] = $this->createHouseholdMember(User::ROLE_CHILD);
        [$memberB, $householdB] = $this->createHouseholdMember(User::ROLE_CHILD);

        HouseholdSetting::query()->create([
            'household_id' => $householdA->id,
            'has_calendar' => true,
            'calendar_config' => [
                'absence_tracking_enabled' => true,
            ],
        ]);
        HouseholdSetting::query()->create([
            'household_id' => $householdB->id,
            'has_calendar' => true,
            'calendar_config' => [
                'absence_tracking_enabled' => true,
            ],
        ]);

        $mealPlan = MealPlan::query()->create([
            'household_id' => $householdA->id,
            'date' => '2026-03-25',
            'meal_type' => 'midi',
            'custom_title' => 'Salade',
        ]);

        Sanctum::actingAs($memberB);

        $this->postJson("/api/calendar/meal-plan/{$mealPlan->id}/attendance", [
            'status' => 'present',
        ])->assertNotFound();
    }

    public function test_selected_member_event_is_visible_only_to_invited_members(): void
    {
        [$parent, $household] = $this->createHouseholdMember(User::ROLE_PARENT);
        $childA = User::factory()->create(['must_change_password' => false]);
        $childB = User::factory()->create(['must_change_password' => false]);
        $household->users()->attach($childA->id, ['role' => User::ROLE_CHILD]);
        $household->users()->attach($childB->id, ['role' => User::ROLE_CHILD]);

        HouseholdSetting::query()->create([
            'household_id' => $household->id,
            'has_calendar' => true,
        ]);

        Sanctum::actingAs($parent);

        $createResponse = $this->postJson('/api/calendar/events', [
            'title' => 'Rendez-vous scolaire',
            'start_at' => '2026-04-05T17:00:00',
            'end_at' => '2026-04-05T18:00:00',
            'audience_mode' => 'selected_members',
            'invited_user_ids' => [$childA->id],
            'response_required' => true,
            'is_shared_with_other_household' => false,
        ])->assertCreated();

        $eventId = (int) ($createResponse->json('event.id') ?? 0);
        $this->assertGreaterThan(0, $eventId);

        $this->assertDatabaseHas('event_invitations', [
            'event_id' => $eventId,
            'household_id' => $household->id,
            'user_id' => $childA->id,
        ]);
        $this->assertDatabaseMissing('event_invitations', [
            'event_id' => $eventId,
            'household_id' => $household->id,
            'user_id' => $childB->id,
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $childA->id,
            'household_id' => $household->id,
            'type' => 'calendar_event_added',
        ]);
        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $childB->id,
            'household_id' => $household->id,
            'type' => 'calendar_event_added',
        ]);

        Sanctum::actingAs($childA);
        $childAResponse = $this->getJson('/api/calendar/board?from=2026-04-01&to=2026-04-10')->assertOk();
        $childAEvents = collect($childAResponse->json('events') ?? []);
        $this->assertTrue(
            $childAEvents->contains(fn (array $item): bool => (int) ($item['id'] ?? 0) === $eventId)
        );

        Sanctum::actingAs($childB);
        $childBResponse = $this->getJson('/api/calendar/board?from=2026-04-01&to=2026-04-10')->assertOk();
        $childBEvents = collect($childBResponse->json('events') ?? []);
        $this->assertFalse(
            $childBEvents->contains(fn (array $item): bool => (int) ($item['id'] ?? 0) === $eventId)
        );
    }

    public function test_only_me_event_is_hidden_from_other_members_and_cannot_be_answered_when_info_only(): void
    {
        [$parent, $household] = $this->createHouseholdMember(User::ROLE_PARENT);
        $child = User::factory()->create(['must_change_password' => false]);
        $household->users()->attach($child->id, ['role' => User::ROLE_CHILD]);

        HouseholdSetting::query()->create([
            'household_id' => $household->id,
            'has_calendar' => true,
        ]);

        Sanctum::actingAs($child);
        $createResponse = $this->postJson('/api/calendar/events', [
            'title' => 'Sport perso',
            'start_at' => '2026-04-06T19:00:00',
            'end_at' => '2026-04-06T20:00:00',
            'audience_mode' => 'only_me',
            'response_required' => false,
            'is_shared_with_other_household' => false,
        ])->assertCreated();

        $eventId = (int) ($createResponse->json('event.id') ?? 0);
        $this->assertGreaterThan(0, $eventId);
        $createResponse->assertJsonPath('event.audience_mode', 'only_me');
        $createResponse->assertJsonPath('event.response_required', false);
        $createResponse->assertJsonPath('event.permissions.can_confirm_participation', false);

        $this->assertDatabaseHas('event_invitations', [
            'event_id' => $eventId,
            'household_id' => $household->id,
            'user_id' => $child->id,
        ]);

        $this->postJson("/api/calendar/events/{$eventId}/participation", [
            'status' => 'participate',
        ])->assertStatus(422);

        Sanctum::actingAs($parent);
        $parentBoard = $this->getJson('/api/calendar/board?from=2026-04-01&to=2026-04-10')->assertOk();
        $parentEvents = collect($parentBoard->json('events') ?? []);
        $this->assertFalse(
            $parentEvents->contains(fn (array $item): bool => (int) ($item['id'] ?? 0) === $eventId)
        );

        $this->postJson("/api/calendar/events/{$eventId}/participation", [
            'status' => 'participate',
        ])->assertNotFound();
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
