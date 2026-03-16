<?php

namespace Tests\Feature\Api;

use App\Models\BudgetSetting;
use App\Models\Household;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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
