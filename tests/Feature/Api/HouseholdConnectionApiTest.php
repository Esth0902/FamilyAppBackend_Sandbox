<?php

namespace Tests\Feature\Api;

use App\Models\Household;
use App\Models\HouseholdLinkRequest;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HouseholdConnectionApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_parent_can_link_two_households_via_code_and_notification_approval(): void
    {
        [$householdA, $parentA] = $this->createHouseholdWithParent('Foyer A');
        [$householdB, $parentB] = $this->createHouseholdWithParent('Foyer B');

        Sanctum::actingAs($parentB);
        $codeResponse = $this->postJson('/api/households/connected-household/link-code', [], [
            'X-Household-Id' => (string) $householdB->id,
        ])->assertOk();

        $code = (string) $codeResponse->json('code.value');
        $this->assertNotSame('', $code);

        Sanctum::actingAs($parentA);
        $this->postJson('/api/households/connected-household/connect', [
            'code' => $code,
        ], [
            'X-Household-Id' => (string) $householdA->id,
        ])
            ->assertStatus(202)
            ->assertJsonPath('request.status', 'pending');

        /** @var HouseholdLinkRequest $linkRequest */
        $linkRequest = HouseholdLinkRequest::query()
            ->where('from_household_id', $householdA->id)
            ->where('to_household_id', $householdB->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('pending', (string) $linkRequest->status);

        /** @var UserNotification $approvalNotification */
        $approvalNotification = UserNotification::query()
            ->where('user_id', $parentB->id)
            ->where('household_id', $householdB->id)
            ->where('type', 'household_link_request')
            ->latest('id')
            ->firstOrFail();

        Sanctum::actingAs($parentB);
        $this->postJson("/api/notifications/{$approvalNotification->id}/household-link-response", [
            'action' => 'accept',
        ], [
            'X-Household-Id' => (string) $householdB->id,
        ])
            ->assertOk()
            ->assertJsonPath('request.status', 'accepted');

        $householdA->refresh();
        $householdB->refresh();
        $this->assertSame((int) $householdB->id, (int) $householdA->linked_household_id);
        $this->assertSame((int) $householdA->id, (int) $householdB->linked_household_id);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $parentA->id,
            'household_id' => $householdA->id,
            'type' => 'household_link_request_responded',
        ]);
    }

    public function test_child_cannot_generate_or_connect_household_link_code(): void
    {
        [$household, , $child] = $this->createHouseholdWithParentAndChild('Foyer parent/enfant');
        Sanctum::actingAs($child);

        $this->postJson('/api/households/connected-household/link-code', [], [
            'X-Household-Id' => (string) $household->id,
        ])->assertForbidden();

        $this->postJson('/api/households/connected-household/connect', [
            'code' => 'ABCD1234',
        ], [
            'X-Household-Id' => (string) $household->id,
        ])->assertForbidden();
    }

    public function test_parent_can_unlink_connected_households(): void
    {
        [$householdA, $parentA] = $this->createHouseholdWithParent('Foyer A unlink');
        [$householdB, $parentB] = $this->createHouseholdWithParent('Foyer B unlink');

        $householdA->forceFill(['linked_household_id' => $householdB->id])->save();
        $householdB->forceFill(['linked_household_id' => $householdA->id])->save();

        Sanctum::actingAs($parentA);
        $this->postJson('/api/households/connected-household/unlink', [], [
            'X-Household-Id' => (string) $householdA->id,
        ])
            ->assertOk()
            ->assertJsonPath('message', 'La liaison entre foyers a été supprimée.');

        $householdA->refresh();
        $householdB->refresh();
        $this->assertNull($householdA->linked_household_id);
        $this->assertNull($householdB->linked_household_id);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $parentB->id,
            'household_id' => $householdB->id,
            'type' => 'household_link_disconnected',
        ]);
    }

    /**
     * @return array{0: Household, 1: User}
     */
    private function createHouseholdWithParent(string $householdName): array
    {
        $parent = User::factory()->create([
            'must_change_password' => false,
        ]);

        $household = Household::query()->create([
            'name' => $householdName,
        ]);

        $household->users()->attach($parent->id, [
            'role' => User::ROLE_PARENT,
            'nickname' => 'Parent',
        ]);

        return [$household, $parent->fresh()];
    }

    /**
     * @return array{0: Household, 1: User, 2: User}
     */
    private function createHouseholdWithParentAndChild(string $householdName): array
    {
        [$household, $parent] = $this->createHouseholdWithParent($householdName);
        $child = User::factory()->create([
            'must_change_password' => false,
        ]);

        $household->users()->attach($child->id, [
            'role' => User::ROLE_CHILD,
            'nickname' => 'Enfant',
        ]);

        return [$household, $parent, $child->fresh()];
    }
}
