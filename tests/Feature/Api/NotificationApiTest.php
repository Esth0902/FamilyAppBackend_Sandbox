<?php

namespace Tests\Feature\Api;

use App\Models\Household;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_user_cannot_mark_another_user_notification_as_read(): void
    {
        $owner = User::factory()->create([
            'must_change_password' => false,
        ]);
        $other = User::factory()->create([
            'must_change_password' => false,
        ]);
        $household = Household::query()->create([
            'name' => 'Foyer notifications lecture',
        ]);

        $notification = UserNotification::query()->create([
            'user_id' => (int) $owner->id,
            'household_id' => (int) $household->id,
            'type' => 'generic',
            'title' => 'Info',
            'body' => 'Contenu',
            'data' => [],
        ]);

        Sanctum::actingAs($other);

        $this->postJson("/api/notifications/{$notification->id}/read")
            ->assertForbidden();
    }

    public function test_user_cannot_respond_to_another_user_household_invite_notification(): void
    {
        $owner = User::factory()->create([
            'must_change_password' => false,
        ]);
        $other = User::factory()->create([
            'must_change_password' => false,
        ]);
        $household = Household::query()->create([
            'name' => 'Foyer notifications reponse',
        ]);

        $notification = UserNotification::query()->create([
            'user_id' => (int) $owner->id,
            'household_id' => (int) $household->id,
            'type' => 'household_invite',
            'title' => 'Invitation',
            'body' => 'Invitation foyer',
            'data' => [
                'status' => 'pending',
                'household_id' => (int) $household->id,
                'invited_role' => User::ROLE_CHILD,
            ],
        ]);

        Sanctum::actingAs($other);

        $this->postJson("/api/notifications/{$notification->id}/household-invite-response", [
            'action' => 'accept',
        ])->assertForbidden();
    }

    public function test_user_can_mark_all_notifications_as_read(): void
    {
        $user = User::factory()->create([
            'must_change_password' => false,
        ]);
        $household = Household::query()->create([
            'name' => 'Foyer notifications all read',
        ]);

        UserNotification::query()->create([
            'user_id' => (int) $user->id,
            'household_id' => (int) $household->id,
            'type' => 'generic',
            'title' => 'Info 1',
            'body' => 'Contenu 1',
            'data' => [],
        ]);
        UserNotification::query()->create([
            'user_id' => (int) $user->id,
            'household_id' => (int) $household->id,
            'type' => 'generic',
            'title' => 'Info 2',
            'body' => 'Contenu 2',
            'data' => [],
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/notifications/read-all', [])
            ->assertOk()
            ->assertJsonPath('updated_count', 2);
    }

    public function test_user_cannot_destroy_another_user_notification(): void
    {
        $owner = User::factory()->create([
            'must_change_password' => false,
        ]);
        $other = User::factory()->create([
            'must_change_password' => false,
        ]);
        $household = Household::query()->create([
            'name' => 'Foyer notifications destroy',
        ]);

        $notification = UserNotification::query()->create([
            'user_id' => (int) $owner->id,
            'household_id' => (int) $household->id,
            'type' => 'generic',
            'title' => 'Info',
            'body' => 'Contenu',
            'data' => [],
        ]);

        Sanctum::actingAs($other);

        $this->deleteJson("/api/notifications/{$notification->id}")
            ->assertForbidden();
    }
}
