<?php

namespace Tests\Feature\Api;

use App\Models\BudgetSetting;
use App\Models\Household;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\HouseholdDeletionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HouseholdMemberApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_parent_can_add_update_and_delete_household_member(): void
    {
        [$household, $parent] = $this->createHouseholdWithMembers();
        Sanctum::actingAs($parent);

        $createResponse = $this->postJson('/api/household/members', [
            'name' => 'Nouveau Membre',
            'role' => User::ROLE_CHILD,
        ]);

        $createResponse
            ->assertCreated()
            ->assertJsonPath('member.name', 'Nouveau Membre')
            ->assertJsonPath('member.role', User::ROLE_CHILD);

        $memberId = (int) $createResponse->json('member.id');
        $generatedEmail = (string) $createResponse->json('generated_email');

        $this->assertNotSame('', $generatedEmail);
        $this->assertTrue(str_ends_with($generatedEmail, '@family.app'));

        $this->assertDatabaseHas('household_user', [
            'household_id' => $household->id,
            'user_id' => $memberId,
            'role' => User::ROLE_CHILD,
        ]);

        $this->assertDatabaseHas('budget_settings', [
            'household_id' => $household->id,
            'user_id' => $memberId,
        ]);

        $this->patchJson("/api/household/members/{$memberId}", [
            'role' => User::ROLE_PARENT,
            'nickname' => 'Parent 2',
        ])
            ->assertOk()
            ->assertJsonPath('member.role', User::ROLE_PARENT)
            ->assertJsonPath('member.nickname', 'Parent 2');

        $this->assertDatabaseMissing('budget_settings', [
            'household_id' => $household->id,
            'user_id' => $memberId,
        ]);

        $this->deleteJson("/api/household/members/{$memberId}")
            ->assertOk()
            ->assertJsonPath('deleted_member_id', $memberId);

        $this->assertDatabaseMissing('household_user', [
            'household_id' => $household->id,
            'user_id' => $memberId,
        ]);
    }

    public function test_child_cannot_manage_household_members(): void
    {
        [, , $child] = $this->createHouseholdWithMembers();
        Sanctum::actingAs($child);

        $this->postJson('/api/household/members', [
            'name' => 'Interdit',
            'role' => User::ROLE_CHILD,
        ])->assertForbidden();
    }

    public function test_parent_can_create_additional_household(): void
    {
        [, $parent] = $this->createHouseholdWithMembers();
        Sanctum::actingAs($parent);

        $response = $this->postJson('/api/households', [
            'household_name' => 'Foyer secondaire',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('household.name', 'Foyer secondaire');

        $newHouseholdId = (int) $response->json('household.id');
        $this->assertGreaterThan(0, $newHouseholdId);

        $this->assertDatabaseHas('household_user', [
            'household_id' => $newHouseholdId,
            'user_id' => $parent->id,
            'role' => User::ROLE_PARENT,
        ]);
    }

    public function test_parent_can_create_additional_household_with_existing_user_invitation(): void
    {
        [, $parent] = $this->createHouseholdWithMembers();
        $existingUser = User::factory()->create([
            'name' => 'Membre Existant',
            'email' => 'membre.existant@example.com',
            'must_change_password' => false,
        ]);
        $otherHousehold = Household::query()->create(['name' => 'Autre foyer']);
        $otherHousehold->users()->attach($existingUser->id, [
            'role' => User::ROLE_PARENT,
            'nickname' => 'Parent autre foyer',
        ]);

        Sanctum::actingAs($parent);

        $response = $this->postJson('/api/households', [
            'household_name' => 'Nouveau foyer',
            'members' => [
                [
                    'name' => 'Membre Existant',
                    'email' => $existingUser->email,
                    'role' => User::ROLE_CHILD,
                ],
            ],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('household.name', 'Nouveau foyer')
            ->assertJsonPath('created_members.0.id', $existingUser->id)
            ->assertJsonPath('created_members.0.invitation_status', 'pending')
            ->assertJsonPath('created_members.0.invited_email', $existingUser->email);

        $newHouseholdId = (int) $response->json('household.id');
        $this->assertGreaterThan(0, $newHouseholdId);

        $this->assertDatabaseHas('user_notifications', [
            'household_id' => $newHouseholdId,
            'user_id' => $existingUser->id,
            'type' => 'household_invite',
        ]);
        $this->assertDatabaseMissing('household_user', [
            'household_id' => $newHouseholdId,
            'user_id' => $existingUser->id,
        ]);
    }

    public function test_parent_invites_existing_user_from_another_household(): void
    {
        [$household, $parent] = $this->createHouseholdWithMembers();
        $existingUser = User::factory()->create([
            'name' => 'Membre Existant',
            'email' => 'deja.utilise@example.com',
            'must_change_password' => false,
        ]);
        $otherHousehold = Household::query()->create(['name' => 'Autre foyer']);
        $otherHousehold->users()->attach($existingUser->id, [
            'role' => User::ROLE_PARENT,
            'nickname' => 'Parent autre foyer',
        ]);

        Sanctum::actingAs($parent);

        $response = $this->postJson('/api/household/members', [
            'name' => 'Membre Existant',
            'email' => $existingUser->email,
            'role' => User::ROLE_CHILD,
        ]);

        $response
            ->assertStatus(202)
            ->assertJsonPath('invitation.status', 'pending')
            ->assertJsonPath('invitation.invited_user_id', $existingUser->id)
            ->assertJsonPath('invitation.household_id', $household->id)
            ->assertJsonPath('invitation.role', User::ROLE_CHILD);

        $this->assertDatabaseHas('user_notifications', [
            'household_id' => $household->id,
            'user_id' => $existingUser->id,
            'type' => 'household_invite',
        ]);
        $this->assertDatabaseMissing('household_user', [
            'household_id' => $household->id,
            'user_id' => $existingUser->id,
        ]);
    }

    public function test_pending_notifications_include_household_invite_even_when_active_household_header_differs(): void
    {
        [$targetHousehold, $parent] = $this->createHouseholdWithMembers('Foyer cible');
        $invitedUser = User::factory()->create([
            'name' => 'Nathan',
            'email' => 'nathan.test.pending@example.com',
            'must_change_password' => false,
        ]);

        $otherHousehold = Household::query()->create(['name' => 'Foyer actif']);
        $otherHousehold->users()->attach($invitedUser->id, [
            'role' => User::ROLE_PARENT,
            'nickname' => 'Nathan actif',
        ]);

        $notification = UserNotification::query()->create([
            'household_id' => $targetHousehold->id,
            'user_id' => $invitedUser->id,
            'type' => 'household_invite',
            'title' => 'Invitation de foyer',
            'body' => 'Invitation test',
            'data' => [
                'household_id' => (int) $targetHousehold->id,
                'household_name' => (string) $targetHousehold->name,
                'inviter_user_id' => (int) $parent->id,
                'inviter_name' => (string) $parent->name,
                'invited_role' => User::ROLE_CHILD,
                'status' => 'pending',
            ],
        ]);

        Sanctum::actingAs($invitedUser);

        $response = $this->getJson('/api/notifications/pending', [
            'X-Household-Id' => (string) $otherHousehold->id,
        ])->assertOk();

        $notificationIds = collect($response->json('notifications'))
            ->pluck('id')
            ->map(static fn($id): int => (int) $id)
            ->values()
            ->all();

        $this->assertContains((int) $notification->id, $notificationIds);
    }

    public function test_pending_notifications_include_unread_non_actionable_notifications_even_after_sent(): void
    {
        [$household, $parent] = $this->createHouseholdWithMembers('Foyer notifications');

        $notification = UserNotification::query()->create([
            'household_id' => $household->id,
            'user_id' => $parent->id,
            'type' => 'task_reassignment_invite_responded',
            'title' => 'Reprise de tâche acceptée',
            'body' => 'Un membre a accepté votre demande.',
            'data' => [
                'household_id' => (int) $household->id,
                'task_instance_id' => 123,
                'status' => 'accepted',
            ],
            'sent_at' => now(),
            'read_at' => null,
        ]);

        Sanctum::actingAs($parent);

        $response = $this->getJson('/api/notifications/pending', [
            'X-Household-Id' => (string) $household->id,
        ])->assertOk();

        $notificationIds = collect($response->json('notifications'))
            ->pluck('id')
            ->map(static fn($id): int => (int) $id)
            ->values()
            ->all();

        $this->assertContains((int) $notification->id, $notificationIds);
    }

    public function test_invited_user_can_accept_household_invitation(): void
    {
        [$household, $parent] = $this->createHouseholdWithMembers();
        $invitedUser = User::factory()->create([
            'name' => 'Utilisateur Invite',
            'must_change_password' => false,
        ]);

        $notification = UserNotification::query()->create([
            'household_id' => $household->id,
            'user_id' => $invitedUser->id,
            'type' => 'household_invite',
            'title' => 'Invitation de foyer',
            'body' => 'Invitation test',
            'data' => [
                'household_id' => (int) $household->id,
                'household_name' => (string) $household->name,
                'inviter_user_id' => (int) $parent->id,
                'inviter_name' => (string) $parent->name,
                'invited_role' => User::ROLE_CHILD,
                'status' => 'pending',
            ],
        ]);

        Sanctum::actingAs($invitedUser);

        $this->postJson("/api/notifications/{$notification->id}/household-invite-response", [
            'action' => 'accept',
        ])
            ->assertOk()
            ->assertJsonPath('invitation.status', 'accepted')
            ->assertJsonPath('invitation.household_id', $household->id)
            ->assertJsonPath('invitation.role', User::ROLE_CHILD)
            ->assertJsonPath('user.id', $invitedUser->id);

        $this->assertDatabaseHas('household_user', [
            'household_id' => $household->id,
            'user_id' => $invitedUser->id,
            'role' => User::ROLE_CHILD,
        ]);
        $this->assertDatabaseHas('budget_settings', [
            'household_id' => $household->id,
            'user_id' => $invitedUser->id,
        ]);

        $notification->refresh();
        $this->assertSame('accepted', (string) data_get($notification->data, 'status'));
        $this->assertNotNull($notification->read_at);

        /** @var UserNotification|null $inviterNotification */
        $inviterNotification = UserNotification::query()
            ->where('user_id', $parent->id)
            ->where('type', 'household_invite_responded')
            ->latest('id')
            ->first();
        $this->assertNotNull($inviterNotification);
        $this->assertSame('accepted', (string) data_get($inviterNotification?->data, 'status'));
        $this->assertSame((int) $invitedUser->id, (int) data_get($inviterNotification?->data, 'invited_user_id'));
    }

    public function test_invited_user_can_refuse_household_invitation(): void
    {
        [$household, $parent] = $this->createHouseholdWithMembers();
        $invitedUser = User::factory()->create([
            'name' => 'Utilisateur Invite',
            'must_change_password' => false,
        ]);

        $notification = UserNotification::query()->create([
            'household_id' => $household->id,
            'user_id' => $invitedUser->id,
            'type' => 'household_invite',
            'title' => 'Invitation de foyer',
            'body' => 'Invitation test',
            'data' => [
                'household_id' => (int) $household->id,
                'household_name' => (string) $household->name,
                'inviter_user_id' => (int) $parent->id,
                'inviter_name' => (string) $parent->name,
                'invited_role' => User::ROLE_PARENT,
                'status' => 'pending',
            ],
        ]);

        Sanctum::actingAs($invitedUser);

        $this->postJson("/api/notifications/{$notification->id}/household-invite-response", [
            'action' => 'refuse',
        ])
            ->assertOk()
            ->assertJsonPath('invitation.status', 'refused')
            ->assertJsonPath('invitation.household_id', $household->id)
            ->assertJsonPath('invitation.role', User::ROLE_PARENT)
            ->assertJsonPath('user.id', $invitedUser->id);

        $this->assertDatabaseMissing('household_user', [
            'household_id' => $household->id,
            'user_id' => $invitedUser->id,
        ]);

        $notification->refresh();
        $this->assertSame('refused', (string) data_get($notification->data, 'status'));
        $this->assertNotNull($notification->read_at);

        /** @var UserNotification|null $inviterNotification */
        $inviterNotification = UserNotification::query()
            ->where('user_id', $parent->id)
            ->where('type', 'household_invite_responded')
            ->latest('id')
            ->first();
        $this->assertNotNull($inviterNotification);
        $this->assertSame('refused', (string) data_get($inviterNotification?->data, 'status'));
        $this->assertSame((int) $invitedUser->id, (int) data_get($inviterNotification?->data, 'invited_user_id'));
    }

    public function test_parent_can_request_household_deletion_with_parent_approval_then_scheduler_deletes_household(): void
    {
        [$household, $parentRequester, $child] = $this->createHouseholdWithMembers('Foyer suppression');
        $secondParent = User::factory()->create([
            'must_change_password' => false,
        ]);
        $household->users()->attach($secondParent->id, [
            'role' => User::ROLE_PARENT,
            'nickname' => 'Parent 2',
        ]);

        Sanctum::actingAs($parentRequester);
        $this->postJson('/api/households/delete-request')
            ->assertOk()
            ->assertJsonPath('deletion_request.status', 'pending_approvals')
            ->assertJsonPath('deletion_request.approvals_required', 1);

        /** @var UserNotification $approvalNotification */
        $approvalNotification = UserNotification::query()
            ->where('household_id', $household->id)
            ->where('user_id', $secondParent->id)
            ->where('type', HouseholdDeletionService::TYPE_APPROVAL_REQUEST)
            ->latest('id')
            ->firstOrFail();

        Sanctum::actingAs($secondParent);
        $this->postJson("/api/notifications/{$approvalNotification->id}/household-deletion-response", [
            'action' => 'accept',
        ])
            ->assertOk()
            ->assertJsonPath('deletion_request.status', 'scheduled');

        $this->assertDatabaseHas('user_notifications', [
            'household_id' => $household->id,
            'user_id' => $parentRequester->id,
            'type' => HouseholdDeletionService::TYPE_CANCEL_WINDOW,
        ]);
        $this->assertDatabaseHas('user_notifications', [
            'household_id' => $household->id,
            'user_id' => $secondParent->id,
            'type' => HouseholdDeletionService::TYPE_CANCEL_WINDOW,
        ]);
        $this->assertDatabaseHas('user_notifications', [
            'household_id' => $household->id,
            'user_id' => $child->id,
            'type' => HouseholdDeletionService::TYPE_SCHEDULED_INFO,
        ]);

        /** @var UserNotification $control */
        $control = UserNotification::query()
            ->where('household_id', $household->id)
            ->where('type', HouseholdDeletionService::TYPE_CONTROL)
            ->latest('id')
            ->firstOrFail();
        $controlData = is_array($control->data) ? $control->data : [];
        $controlData['scheduled_delete_at'] = Carbon::now()->subMinute()->toIso8601String();
        $controlData['status'] = 'scheduled';
        $control->forceFill(['data' => $controlData])->save();

        app(HouseholdDeletionService::class)->processScheduledDeletions();

        $this->assertDatabaseMissing('households', [
            'id' => $household->id,
        ]);
    }

    public function test_parent_refusal_cancels_household_deletion_request_and_requester_can_leave(): void
    {
        [$household, $parentRequester] = $this->createHouseholdWithMembers('Foyer refus suppression');
        $secondParent = User::factory()->create([
            'must_change_password' => false,
        ]);
        $household->users()->attach($secondParent->id, [
            'role' => User::ROLE_PARENT,
            'nickname' => 'Parent 2',
        ]);

        Sanctum::actingAs($parentRequester);
        $this->postJson('/api/households/delete-request')
            ->assertOk()
            ->assertJsonPath('deletion_request.status', 'pending_approvals');

        /** @var UserNotification $approvalNotification */
        $approvalNotification = UserNotification::query()
            ->where('household_id', $household->id)
            ->where('user_id', $secondParent->id)
            ->where('type', HouseholdDeletionService::TYPE_APPROVAL_REQUEST)
            ->latest('id')
            ->firstOrFail();

        Sanctum::actingAs($secondParent);
        $this->postJson("/api/notifications/{$approvalNotification->id}/household-deletion-response", [
            'action' => 'refuse',
        ])
            ->assertOk()
            ->assertJsonPath('deletion_request.status', 'cancelled');

        $this->assertDatabaseHas('user_notifications', [
            'household_id' => $household->id,
            'user_id' => $parentRequester->id,
            'type' => HouseholdDeletionService::TYPE_REQUEST_REFUSED,
        ]);

        Sanctum::actingAs($parentRequester);
        $this->postJson('/api/households/leave')
            ->assertOk()
            ->assertJsonPath('left_household_id', $household->id);

        $this->assertDatabaseMissing('household_user', [
            'household_id' => $household->id,
            'user_id' => $parentRequester->id,
        ]);
    }

    public function test_single_parent_request_schedules_household_deletion_without_parent_approval(): void
    {
        [$household, $parent, $child] = $this->createHouseholdWithMembers('Foyer parent unique');
        Sanctum::actingAs($parent);

        $this->postJson('/api/households/delete-request')
            ->assertOk()
            ->assertJsonPath('deletion_request.status', 'scheduled')
            ->assertJsonPath('deletion_request.approvals_required', 0);

        $this->assertDatabaseMissing('user_notifications', [
            'household_id' => $household->id,
            'type' => HouseholdDeletionService::TYPE_APPROVAL_REQUEST,
        ]);
        $this->assertDatabaseHas('user_notifications', [
            'household_id' => $household->id,
            'user_id' => $parent->id,
            'type' => HouseholdDeletionService::TYPE_CANCEL_WINDOW,
        ]);
        $this->assertDatabaseHas('user_notifications', [
            'household_id' => $household->id,
            'user_id' => $child->id,
            'type' => HouseholdDeletionService::TYPE_SCHEDULED_INFO,
        ]);
    }

    public function test_child_cannot_update_household_config_or_create_dietary_tags(): void
    {
        [, , $child] = $this->createHouseholdWithMembers();
        Sanctum::actingAs($child);

        $this->patchJson('/api/households/config', [
            'modules' => [],
        ])->assertForbidden();

        $this->postJson('/api/households/dietary-tags', [
            'label' => 'Sans lactose',
            'type' => 'restriction',
        ])->assertForbidden();
    }

    public function test_parent_cannot_remove_last_parent_role(): void
    {
        [, $parent] = $this->createHouseholdWithMembers();
        Sanctum::actingAs($parent);

        $this->patchJson("/api/household/members/{$parent->id}", [
            'role' => User::ROLE_CHILD,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['role']);
    }

    public function test_parent_leave_requires_new_parent_or_household_deletion_when_last_parent(): void
    {
        [$household, $parent, $child] = $this->createHouseholdWithMembers();
        Sanctum::actingAs($parent);

        $this->postJson('/api/households/leave', [], [
            'X-Household-Id' => (string) $household->id,
        ])
            ->assertStatus(422)
            ->assertJsonPath('required_action', 'define_new_parent_or_delete_household')
            ->assertJsonPath('household.id', (int) $household->id)
            ->assertJsonPath('candidate_members.0.id', (int) $child->id);
    }

    public function test_delete_account_is_blocked_when_user_is_last_parent_of_a_household(): void
    {
        [$household, $parent] = $this->createHouseholdWithMembers();
        Sanctum::actingAs($parent);

        $this->deleteJson('/api/auth/account', [
            'current_password' => 'password',
        ])
            ->assertStatus(422)
            ->assertJsonPath('required_action', 'define_new_parent_or_delete_household')
            ->assertJsonPath('blocked_households.0.household.id', (int) $household->id);

        $this->assertDatabaseHas('users', [
            'id' => (int) $parent->id,
        ]);
    }

    public function test_delete_account_succeeds_when_another_parent_remains(): void
    {
        [$household, $parent] = $this->createHouseholdWithMembers();
        $secondParent = User::factory()->create([
            'must_change_password' => false,
        ]);

        $household->users()->attach($secondParent->id, [
            'role' => User::ROLE_PARENT,
            'nickname' => 'Parent 2',
        ]);

        Sanctum::actingAs($parent);

        $this->deleteJson('/api/auth/account', [
            'current_password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Compte utilisateur supprimé définitivement.');

        $this->assertDatabaseMissing('users', [
            'id' => (int) $parent->id,
        ]);
        $this->assertDatabaseMissing('household_user', [
            'household_id' => (int) $household->id,
            'user_id' => (int) $parent->id,
        ]);
    }

    public function test_members_endpoint_only_returns_current_household_members(): void
    {
        [$householdA, $parentA, $childA] = $this->createHouseholdWithMembers();
        [, $parentB, $childB] = $this->createHouseholdWithMembers('Foyer B');
        Sanctum::actingAs($parentA);

        $response = $this->getJson('/api/household/members')
            ->assertOk()
            ->assertJsonPath('household.id', $householdA->id);

        $memberIds = collect($response->json('members'))->pluck('id')->map(fn($id) => (int) $id);
        $this->assertTrue($memberIds->contains((int) $parentA->id));
        $this->assertTrue($memberIds->contains((int) $childA->id));
        $this->assertFalse($memberIds->contains((int) $parentB->id));
        $this->assertFalse($memberIds->contains((int) $childB->id));
    }

    public function test_parent_can_refresh_temporary_access_for_member_who_must_change_password(): void
    {
        [, $parent, $child] = $this->createHouseholdWithMembers();
        $child->forceFill([
            'must_change_password' => true,
        ])->save();

        Sanctum::actingAs($parent);

        $this->postJson("/api/household/members/{$child->id}/temporary-access")
            ->assertOk()
            ->assertJsonPath('member.id', $child->id)
            ->assertJsonPath('member.must_change_password', true)
            ->assertJsonStructure([
                'generated_email',
                'generated_password',
                'share_text',
            ]);
    }

    public function test_parent_cannot_refresh_temporary_access_if_member_already_changed_password(): void
    {
        [, $parent, $child] = $this->createHouseholdWithMembers();
        $child->forceFill([
            'must_change_password' => false,
        ])->save();

        Sanctum::actingAs($parent);

        $this->postJson("/api/household/members/{$child->id}/temporary-access")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['member']);
    }

    /**
     * @return array{0: Household, 1: User, 2: User}
     */
    private function createHouseholdWithMembers(string $householdName = 'Foyer test membres'): array
    {
        $parent = User::factory()->create([
            'must_change_password' => false,
        ]);

        $child = User::factory()->create([
            'must_change_password' => false,
        ]);

        $household = Household::query()->create([
            'name' => $householdName,
        ]);

        $household->users()->attach($parent->id, [
            'role' => User::ROLE_PARENT,
            'nickname' => 'Parent',
        ]);
        $household->users()->attach($child->id, [
            'role' => User::ROLE_CHILD,
            'nickname' => 'Enfant',
        ]);

        BudgetSetting::query()->create([
            'household_id' => $household->id,
            'user_id' => $child->id,
            'base_amount' => 0,
            'recurrence' => 'weekly',
            'reset_day' => 1,
            'allow_advances' => false,
            'max_advance_amount' => 0,
        ]);

        return [$household, $parent->fresh(), $child->fresh()];
    }
}
