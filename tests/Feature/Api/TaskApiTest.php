<?php

namespace Tests\Feature\Api;

use App\Models\Household;
use App\Models\HouseholdSetting;
use App\Models\TaskInstance;
use App\Models\TaskTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TaskApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_assigned_child_can_mark_task_as_done(): void
    {
        [$parent, $child] = $this->createHouseholdWithMembers();
        $household = $parent->households()->firstOrFail();

        HouseholdSetting::query()->create([
            'household_id' => $household->id,
            'has_tasks' => true,
            'has_calendar' => true,
        ]);

        $template = TaskTemplate::query()->create([
            'household_id' => $household->id,
            'name' => 'Sortir les poubelles',
            'description' => 'Le mercredi soir',
            'recurrence' => 'once',
            'is_rotation' => false,
            'rotation_cycle_weeks' => 1,
        ]);

        $instance = TaskInstance::query()->create([
            'task_template_id' => $template->id,
            'user_id' => $child->id,
            'due_date' => '2026-03-06',
            'status' => 'à faire',
            'validated_by_parent' => false,
        ]);

        Sanctum::actingAs($child);

        $this->patchJson("/api/tasks/instances/{$instance->id}", [
            'status' => 'réalisée',
        ])
            ->assertOk()
            ->assertJsonPath('instance.id', $instance->id)
            ->assertJsonPath('instance.status', 'réalisée')
            ->assertJsonPath('instance.validated_by_parent', false);

        $this->assertDatabaseHas('task_instances', [
            'id' => $instance->id,
            'status' => 'réalisée',
            'validated_by_parent' => false,
        ]);
    }

    public function test_child_cannot_update_task_assigned_to_someone_else(): void
    {
        [$parent, $childA, $childB] = $this->createHouseholdWithMembers(2);
        $household = $parent->households()->firstOrFail();

        HouseholdSetting::query()->create([
            'household_id' => $household->id,
            'has_tasks' => true,
            'has_calendar' => true,
        ]);

        $template = TaskTemplate::query()->create([
            'household_id' => $household->id,
            'name' => 'Passer l aspirateur',
            'description' => null,
            'recurrence' => 'once',
            'is_rotation' => false,
            'rotation_cycle_weeks' => 1,
        ]);

        $instance = TaskInstance::query()->create([
            'task_template_id' => $template->id,
            'user_id' => $childB->id,
            'due_date' => '2026-03-07',
            'status' => 'à faire',
            'validated_by_parent' => false,
        ]);

        Sanctum::actingAs($childA);

        $this->patchJson("/api/tasks/instances/{$instance->id}", [
            'status' => 'réalisée',
        ])->assertForbidden();
    }

    public function test_parent_can_validate_done_task(): void
    {
        [$parent, $child] = $this->createHouseholdWithMembers();
        $household = $parent->households()->firstOrFail();

        HouseholdSetting::query()->create([
            'household_id' => $household->id,
            'has_tasks' => true,
            'has_calendar' => true,
        ]);

        $template = TaskTemplate::query()->create([
            'household_id' => $household->id,
            'name' => 'Ranger la chambre',
            'description' => null,
            'recurrence' => 'once',
            'is_rotation' => false,
            'rotation_cycle_weeks' => 1,
        ]);

        $instance = TaskInstance::query()->create([
            'task_template_id' => $template->id,
            'user_id' => $child->id,
            'due_date' => '2026-03-08',
            'status' => 'réalisée',
            'validated_by_parent' => false,
            'completed_at' => now(),
        ]);

        Sanctum::actingAs($parent);

        $this->postJson("/api/tasks/instances/{$instance->id}/validate")
            ->assertOk()
            ->assertJsonPath('instance.id', $instance->id)
            ->assertJsonPath('instance.status', 'réalisée')
            ->assertJsonPath('instance.validated_by_parent', true);

        $this->assertDatabaseHas('task_instances', [
            'id' => $instance->id,
            'status' => 'réalisée',
            'validated_by_parent' => true,
        ]);
    }

    public function test_child_cannot_validate_task_even_in_own_household(): void
    {
        [$parent, $child] = $this->createHouseholdWithMembers();
        $household = $parent->households()->firstOrFail();

        HouseholdSetting::query()->create([
            'household_id' => $household->id,
            'has_tasks' => true,
            'has_calendar' => true,
        ]);

        $template = TaskTemplate::query()->create([
            'household_id' => $household->id,
            'name' => 'Plier le linge',
            'description' => null,
            'recurrence' => 'once',
            'is_rotation' => false,
            'rotation_cycle_weeks' => 1,
        ]);

        $instance = TaskInstance::query()->create([
            'task_template_id' => $template->id,
            'user_id' => $child->id,
            'due_date' => '2026-03-09',
            'status' => 'réalisée',
            'validated_by_parent' => false,
            'completed_at' => now(),
        ]);

        Sanctum::actingAs($child);

        $this->postJson("/api/tasks/instances/{$instance->id}/validate")
            ->assertForbidden();

        $this->assertDatabaseHas('task_instances', [
            'id' => $instance->id,
            'validated_by_parent' => false,
        ]);
    }

    public function test_user_cannot_update_task_instance_from_another_household(): void
    {
        [$parentA, $childA] = $this->createHouseholdWithMembers();
        $householdA = $parentA->households()->firstOrFail();

        HouseholdSetting::query()->create([
            'household_id' => $householdA->id,
            'has_tasks' => true,
            'has_calendar' => true,
        ]);

        $template = TaskTemplate::query()->create([
            'household_id' => $householdA->id,
            'name' => 'Nettoyer la table',
            'description' => null,
            'recurrence' => 'once',
            'is_rotation' => false,
            'rotation_cycle_weeks' => 1,
        ]);

        $instance = TaskInstance::query()->create([
            'task_template_id' => $template->id,
            'user_id' => $childA->id,
            'due_date' => '2026-03-10',
            'status' => 'à faire',
            'validated_by_parent' => false,
        ]);

        [$otherParent] = $this->createHouseholdWithMembers(0);
        $otherHousehold = $otherParent->households()->firstOrFail();

        HouseholdSetting::query()->create([
            'household_id' => $otherHousehold->id,
            'has_tasks' => true,
            'has_calendar' => true,
        ]);

        Sanctum::actingAs($otherParent);

        $this->patchJson("/api/tasks/instances/{$instance->id}", [
            'status' => 'réalisée',
        ])->assertNotFound();

        $this->assertDatabaseHas('task_instances', [
            'id' => $instance->id,
            'status' => 'à faire',
            'validated_by_parent' => false,
        ]);
    }

    public function test_weekly_template_with_inter_household_alternation_generates_every_other_week(): void
    {
        [$parent, $child] = $this->createHouseholdWithMembers();
        $household = $parent->households()->firstOrFail();

        HouseholdSetting::query()->create([
            'household_id' => $household->id,
            'has_tasks' => true,
            'has_calendar' => true,
        ]);

        TaskTemplate::query()->create([
            'household_id' => $household->id,
            'name' => 'Sortir les poubelles',
            'description' => 'Une semaine sur deux',
            'recurrence' => 'weekly',
            'recurrence_days' => [1],
            'is_rotation' => false,
            'rotation_cycle_weeks' => 1,
            'is_inter_household_alternating' => true,
            'inter_household_week_start' => '2026-03-02',
            'fixed_user_id' => $child->id,
        ]);

        Sanctum::actingAs($parent);

        $response = $this->getJson('/api/tasks/board?from=2026-03-02&to=2026-03-23')
            ->assertOk();

        $dueDates = collect($response->json('instances'))
            ->pluck('due_date')
            ->values()
            ->all();

        $this->assertSame(['2026-03-02', '2026-03-16'], $dueDates);
        $this->assertDatabaseHas('task_instances', [
            'due_date' => '2026-03-02',
            'user_id' => $child->id,
        ]);
        $this->assertDatabaseHas('task_instances', [
            'due_date' => '2026-03-16',
            'user_id' => $child->id,
        ]);
        $this->assertDatabaseMissing('task_instances', [
            'due_date' => '2026-03-09',
        ]);
        $this->assertDatabaseMissing('task_instances', [
            'due_date' => '2026-03-23',
        ]);
    }

    public function test_global_alternating_custody_with_friday_change_day_generates_child_tasks_every_other_week(): void
    {
        [$parent, $child] = $this->createHouseholdWithMembers();
        $household = $parent->households()->firstOrFail();

        HouseholdSetting::query()->create([
            'household_id' => $household->id,
            'has_tasks' => true,
            'has_calendar' => true,
            'tasks_config' => [
                'alternating_custody_enabled' => true,
                'custody_change_day' => 5,
                'custody_home_week_start' => '2026-03-06',
            ],
        ]);

        TaskTemplate::query()->create([
            'household_id' => $household->id,
            'name' => 'Sortir les poubelles',
            'description' => 'Le vendredi',
            'recurrence' => 'weekly',
            'recurrence_days' => [5],
            'is_rotation' => false,
            'rotation_cycle_weeks' => 1,
            'fixed_user_id' => $child->id,
        ]);

        Sanctum::actingAs($parent);

        $response = $this->getJson('/api/tasks/board?from=2026-03-06&to=2026-03-27')
            ->assertOk();

        $dueDates = collect($response->json('instances'))
            ->pluck('due_date')
            ->values()
            ->all();

        $this->assertSame(['2026-03-06', '2026-03-20'], $dueDates);
        $this->assertDatabaseHas('task_instances', [
            'due_date' => '2026-03-06',
            'user_id' => $child->id,
        ]);
        $this->assertDatabaseHas('task_instances', [
            'due_date' => '2026-03-20',
            'user_id' => $child->id,
        ]);
        $this->assertDatabaseMissing('task_instances', [
            'due_date' => '2026-03-13',
        ]);
        $this->assertDatabaseMissing('task_instances', [
            'due_date' => '2026-03-27',
        ]);
    }

    public function test_parent_can_create_punctual_task_range_with_end_date(): void
    {
        [$parent, $child] = $this->createHouseholdWithMembers();
        $household = $parent->households()->firstOrFail();

        HouseholdSetting::query()->create([
            'household_id' => $household->id,
            'has_tasks' => true,
            'has_calendar' => true,
        ]);

        Sanctum::actingAs($parent);

        $response = $this->postJson('/api/tasks/instances', [
            'name' => 'Nourrir les chats',
            'description' => 'Pendant les vacances',
            'due_date' => '2026-03-10',
            'end_date' => '2026-03-12',
            'user_id' => $child->id,
        ])->assertCreated();

        $templateId = (int) ($response->json('instance.task_template_id') ?? 0);

        $this->assertGreaterThan(0, $templateId);
        $response->assertJsonPath('instance.due_date', '2026-03-10');
        $this->assertDatabaseHas('task_instances', [
            'task_template_id' => $templateId,
            'due_date' => '2026-03-10',
            'user_id' => $child->id,
        ]);
        $this->assertDatabaseHas('task_instances', [
            'task_template_id' => $templateId,
            'due_date' => '2026-03-11',
            'user_id' => $child->id,
        ]);
        $this->assertDatabaseHas('task_instances', [
            'task_template_id' => $templateId,
            'due_date' => '2026-03-12',
            'user_id' => $child->id,
        ]);
    }

    public function test_monthly_template_start_date_is_used_as_anchor(): void
    {
        [$parent, $child] = $this->createHouseholdWithMembers();
        $household = $parent->households()->firstOrFail();

        HouseholdSetting::query()->create([
            'household_id' => $household->id,
            'has_tasks' => true,
            'has_calendar' => true,
        ]);

        TaskTemplate::query()->create([
            'household_id' => $household->id,
            'name' => 'Nettoyer les filtres',
            'description' => 'Chaque mois',
            'recurrence' => 'monthly',
            'start_date' => '2026-03-15',
            'is_rotation' => false,
            'rotation_cycle_weeks' => 1,
            'fixed_user_id' => $child->id,
        ]);

        Sanctum::actingAs($parent);

        $marchResponse = $this->getJson('/api/tasks/board?from=2026-03-01&to=2026-03-31')
            ->assertOk()
            ->assertJsonPath('templates.0.start_date', '2026-03-15');

        $marchDueDates = collect($marchResponse->json('instances'))
            ->pluck('due_date')
            ->values()
            ->all();
        $this->assertSame(['2026-03-15'], $marchDueDates);

        $aprilResponse = $this->getJson('/api/tasks/board?from=2026-04-01&to=2026-04-30')
            ->assertOk();
        $aprilDueDates = collect($aprilResponse->json('instances'))
            ->pluck('due_date')
            ->values()
            ->all();
        $this->assertSame(['2026-04-15'], $aprilDueDates);

        $this->assertDatabaseMissing('task_instances', [
            'due_date' => '2026-03-01',
        ]);
    }

    private function createHouseholdWithMembers(int $childCount = 1): array
    {
        $parent = User::factory()->create([
            'must_change_password' => false,
        ]);

        $household = Household::query()->create([
            'name' => 'Foyer test taches',
        ]);

        $household->users()->attach($parent->id, [
            'role' => User::ROLE_PARENT,
        ]);

        $members = [$parent->fresh()];
        for ($index = 0; $index < $childCount; $index++) {
            $child = User::factory()->create([
                'must_change_password' => false,
            ]);

            $household->users()->attach($child->id, [
                'role' => User::ROLE_CHILD,
            ]);

            $members[] = $child->fresh();
        }

        return $members;
    }
}


