<?php

namespace Tests\Feature\Api;

use App\Models\BudgetSetting;
use App\Models\Household;
use App\Models\User;
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
