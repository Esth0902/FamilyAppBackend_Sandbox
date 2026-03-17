<?php

namespace Tests\Feature\Api;

use App\Models\Household;
use App\Models\HouseholdSetting;
use App\Models\TaskInstance;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Models\UserNotification;
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

        $template = TaskTemplate::query()->create([
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
            'task_template_id' => $template->id,
        ]);
        $this->assertDatabaseMissing('task_instances', [
            'due_date' => '2026-03-23',
            'task_template_id' => $template->id,
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

        $template = TaskTemplate::query()->create([
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
            'task_template_id' => $template->id,
        ]);
        $this->assertDatabaseMissing('task_instances', [
            'due_date' => '2026-03-27',
            'task_template_id' => $template->id,
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

    public function test_parent_can_create_routine_with_multiple_assignees(): void
    {
        [$parent, $childA, $childB] = $this->createHouseholdWithMembers(2);
        $household = $parent->households()->firstOrFail();

        HouseholdSetting::query()->create([
            'household_id' => $household->id,
            'has_tasks' => true,
            'has_calendar' => true,
        ]);

        Sanctum::actingAs($parent);

        $this->postJson('/api/tasks/templates', [
            'name' => 'Arroser les plantes',
            'description' => 'Chaque lundi',
            'recurrence' => 'weekly',
            'start_date' => '2026-03-09',
            'end_date' => '2026-03-30',
            'recurrence_days' => [1],
            'is_rotation' => false,
            'assignee_user_ids' => [$childA->id, $childB->id],
        ])
            ->assertCreated()
            ->assertJsonPath('template.recurrence', 'weekly')
            ->assertJsonPath('template.start_date', '2026-03-09')
            ->assertJsonPath('template.end_date', '2026-03-30')
            ->assertJsonPath('template.assignee_user_ids.0', $childA->id)
            ->assertJsonPath('template.assignee_user_ids.1', $childB->id);
    }

    public function test_parent_creating_routine_sends_notification_to_assignees(): void
    {
        [$parent, $childA, $childB] = $this->createHouseholdWithMembers(2);
        $household = $parent->households()->firstOrFail();

        HouseholdSetting::query()->create([
            'household_id' => $household->id,
            'has_tasks' => true,
            'has_calendar' => true,
        ]);

        Sanctum::actingAs($parent);

        $response = $this->postJson('/api/tasks/templates', [
            'name' => 'Arroser les plantes',
            'description' => 'Chaque lundi',
            'recurrence' => 'weekly',
            'start_date' => '2026-03-09',
            'recurrence_days' => [1],
            'is_rotation' => false,
            'assignee_user_ids' => [$childA->id, $childB->id],
        ])->assertCreated();

        $templateId = (int) ($response->json('template.id') ?? 0);
        $this->assertGreaterThan(0, $templateId);

        /** @var UserNotification|null $childANotification */
        $childANotification = UserNotification::query()
            ->where('user_id', $childA->id)
            ->where('household_id', $household->id)
            ->where('type', 'task_routine_assigned')
            ->latest('id')
            ->first();
        $this->assertNotNull($childANotification);
        $this->assertSame($templateId, (int) data_get($childANotification?->data, 'task_template_id'));

        /** @var UserNotification|null $childBNotification */
        $childBNotification = UserNotification::query()
            ->where('user_id', $childB->id)
            ->where('household_id', $household->id)
            ->where('type', 'task_routine_assigned')
            ->latest('id')
            ->first();
        $this->assertNotNull($childBNotification);
        $this->assertSame($templateId, (int) data_get($childBNotification?->data, 'task_template_id'));

        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $parent->id,
            'household_id' => $household->id,
            'type' => 'task_routine_assigned',
        ]);
    }

    public function test_daily_and_weekly_recurrence_are_auto_normalized_based_on_selected_days(): void
    {
        [$parent, $child] = $this->createHouseholdWithMembers();
        $household = $parent->households()->firstOrFail();

        HouseholdSetting::query()->create([
            'household_id' => $household->id,
            'has_tasks' => true,
            'has_calendar' => true,
        ]);

        Sanctum::actingAs($parent);

        $createResponse = $this->postJson('/api/tasks/templates', [
            'name' => 'Passer le balai',
            'recurrence' => 'daily',
            'start_date' => '2026-03-01',
            'recurrence_days' => [1, 2, 3],
            'assignee_user_ids' => [$child->id],
        ])
            ->assertCreated()
            ->assertJsonPath('template.recurrence', 'weekly')
            ->assertJsonPath('template.recurrence_days.0', 1)
            ->assertJsonPath('template.recurrence_days.2', 3);

        $templateId = (int) ($createResponse->json('template.id') ?? 0);
        $this->assertGreaterThan(0, $templateId);

        $this->patchJson("/api/tasks/templates/{$templateId}", [
            'recurrence' => 'weekly',
            'recurrence_days' => [1, 2, 3, 4, 5, 6, 7],
        ])
            ->assertOk()
            ->assertJsonPath('template.recurrence', 'daily');
    }

    public function test_recurring_generation_respects_template_start_and_end_dates(): void
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
            'name' => 'Nettoyer la table',
            'recurrence' => 'weekly',
            'start_date' => '2026-03-09',
            'end_date' => '2026-03-16',
            'recurrence_days' => [1],
            'assignee_user_ids' => [$child->id],
            'is_rotation' => false,
            'rotation_cycle_weeks' => 1,
            'fixed_user_id' => $child->id,
        ]);

        Sanctum::actingAs($parent);

        $response = $this->getJson('/api/tasks/board?from=2026-03-02&to=2026-03-30')
            ->assertOk();

        $dueDates = collect($response->json('instances'))
            ->pluck('due_date')
            ->values()
            ->all();

        $this->assertSame(['2026-03-09', '2026-03-16'], $dueDates);
        $this->assertDatabaseMissing('task_instances', [
            'due_date' => '2026-03-02',
            'task_template_id' => $template->id,
        ]);
        $this->assertDatabaseMissing('task_instances', [
            'due_date' => '2026-03-23',
            'task_template_id' => $template->id,
        ]);
    }

    public function test_parent_can_create_punctual_task_with_multiple_assignees(): void
    {
        [$parent, $childA, $childB] = $this->createHouseholdWithMembers(2);
        $household = $parent->households()->firstOrFail();

        HouseholdSetting::query()->create([
            'household_id' => $household->id,
            'has_tasks' => true,
            'has_calendar' => true,
        ]);

        Sanctum::actingAs($parent);

        $response = $this->postJson('/api/tasks/instances', [
            'name' => 'Lancer une machine',
            'due_date' => '2026-03-18',
            'end_date' => '2026-03-18',
            'user_ids' => [$childA->id, $childB->id],
        ])->assertCreated();

        $instanceId = (int) ($response->json('instance.id') ?? 0);
        $this->assertGreaterThan(0, $instanceId);
        $this->assertDatabaseHas('task_instance_assignments', [
            'task_instance_id' => $instanceId,
            'user_id' => $childA->id,
        ]);
        $this->assertDatabaseHas('task_instance_assignments', [
            'task_instance_id' => $instanceId,
            'user_id' => $childB->id,
        ]);
    }

    public function test_assigned_child_can_request_reassignment_and_invited_member_can_accept(): void
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
            'name' => 'Sortir les cartons',
            'recurrence' => 'once',
            'is_rotation' => false,
            'rotation_cycle_weeks' => 1,
        ]);

        $instance = TaskInstance::query()->create([
            'task_template_id' => $template->id,
            'user_id' => $childA->id,
            'due_date' => '2026-03-21',
            'status' => 'à faire',
            'validated_by_parent' => false,
        ]);

        Sanctum::actingAs($childA);
        $this->postJson("/api/tasks/instances/{$instance->id}/reassignment-request", [
            'invited_user_id' => $childB->id,
        ])
            ->assertStatus(202);

        /** @var UserNotification $notification */
        $notification = UserNotification::query()
            ->where('type', 'task_reassignment_invite')
            ->where('user_id', $childB->id)
            ->latest('id')
            ->firstOrFail();

        Sanctum::actingAs($childB);
        $this->postJson("/api/notifications/{$notification->id}/task-reassignment-response", [
            'action' => 'accept',
        ])
            ->assertOk()
            ->assertJsonPath('invitation.status', 'accepted');

        $this->assertDatabaseHas('task_instances', [
            'id' => $instance->id,
            'user_id' => $childB->id,
        ]);
        $this->assertDatabaseHas('task_instance_assignments', [
            'task_instance_id' => $instance->id,
            'user_id' => $childB->id,
        ]);
        $this->assertDatabaseMissing('task_instance_assignments', [
            'task_instance_id' => $instance->id,
            'user_id' => $childA->id,
        ]);

        /** @var UserNotification|null $requesterNotification */
        $requesterNotification = UserNotification::query()
            ->where('user_id', $childA->id)
            ->where('type', 'task_reassignment_invite_responded')
            ->latest('id')
            ->first();
        $this->assertNotNull($requesterNotification);
        $this->assertSame('accepted', (string) data_get($requesterNotification?->data, 'status'));
        $this->assertSame((int) $instance->id, (int) data_get($requesterNotification?->data, 'task_instance_id'));
    }

    public function test_assigned_child_can_request_reassignment_and_invited_member_can_refuse(): void
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
            'name' => 'Sortir les cartons',
            'recurrence' => 'once',
            'is_rotation' => false,
            'rotation_cycle_weeks' => 1,
        ]);

        $instance = TaskInstance::query()->create([
            'task_template_id' => $template->id,
            'user_id' => $childA->id,
            'due_date' => '2026-03-22',
            'status' => "\u{00E0} faire",
            'validated_by_parent' => false,
        ]);

        Sanctum::actingAs($childA);
        $this->postJson("/api/tasks/instances/{$instance->id}/reassignment-request", [
            'invited_user_id' => $childB->id,
        ])->assertStatus(202);

        /** @var UserNotification $notification */
        $notification = UserNotification::query()
            ->where('type', 'task_reassignment_invite')
            ->where('user_id', $childB->id)
            ->latest('id')
            ->firstOrFail();

        Sanctum::actingAs($childB);
        $this->postJson("/api/notifications/{$notification->id}/task-reassignment-response", [
            'action' => 'refuse',
        ])
            ->assertOk()
            ->assertJsonPath('invitation.status', 'refused');

        $this->assertDatabaseHas('task_instances', [
            'id' => $instance->id,
            'user_id' => $childA->id,
        ]);

        /** @var UserNotification|null $requesterNotification */
        $requesterNotification = UserNotification::query()
            ->where('user_id', $childA->id)
            ->where('type', 'task_reassignment_invite_responded')
            ->latest('id')
            ->first();
        $this->assertNotNull($requesterNotification);
        $this->assertSame('refused', (string) data_get($requesterNotification?->data, 'status'));
        $this->assertSame((int) $instance->id, (int) data_get($requesterNotification?->data, 'task_instance_id'));
    }

    public function test_accepted_reassignment_is_preserved_on_recurring_instance_generation(): void
    {
        [$parent, $childA, $childB] = $this->createHouseholdWithMembers(2);
        $household = $parent->households()->firstOrFail();

        HouseholdSetting::query()->create([
            'household_id' => $household->id,
            'has_tasks' => true,
            'has_calendar' => true,
        ]);

        TaskTemplate::query()->create([
            'household_id' => $household->id,
            'name' => 'Vider le lave-vaisselle',
            'recurrence' => 'weekly',
            'start_date' => '2026-03-10',
            'recurrence_days' => [2],
            'assignee_user_ids' => [$childA->id],
            'is_rotation' => false,
            'rotation_cycle_weeks' => 1,
            'fixed_user_id' => $childA->id,
        ]);

        Sanctum::actingAs($parent);
        $this->getJson('/api/tasks/board?from=2026-03-16&to=2026-03-18')->assertOk();

        /** @var TaskInstance $instance */
        $instance = TaskInstance::query()
            ->whereDate('due_date', '2026-03-17')
            ->orderByDesc('id')
            ->firstOrFail();

        Sanctum::actingAs($childA);
        $this->postJson("/api/tasks/instances/{$instance->id}/reassignment-request", [
            'invited_user_id' => $childB->id,
        ])->assertStatus(202);

        /** @var UserNotification $notification */
        $notification = UserNotification::query()
            ->where('type', 'task_reassignment_invite')
            ->where('user_id', $childB->id)
            ->latest('id')
            ->firstOrFail();

        Sanctum::actingAs($childB);
        $this->postJson("/api/notifications/{$notification->id}/task-reassignment-response", [
            'action' => 'accept',
        ])->assertOk();

        $this->assertDatabaseHas('task_instances', [
            'id' => $instance->id,
            'user_id' => $childB->id,
        ]);

        Sanctum::actingAs($parent);
        $response = $this->getJson('/api/tasks/board?from=2026-03-16&to=2026-03-18')->assertOk();

        $payloadInstance = collect($response->json('instances'))
            ->first(static fn(array $candidate): bool => (int) ($candidate['id'] ?? 0) === (int) $instance->id);

        $this->assertNotNull($payloadInstance);
        $this->assertSame($childB->id, (int) data_get($payloadInstance, 'assignee.id'));
        $this->assertSame([$childB->id], collect(data_get($payloadInstance, 'assignees', []))
            ->pluck('id')
            ->map(static fn($id): int => (int) $id)
            ->values()
            ->all());
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



