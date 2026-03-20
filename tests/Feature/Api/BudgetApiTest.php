<?php

namespace Tests\Feature\Api;

use App\Domain\Budget\ValueObjects\BudgetComment;
use App\Models\BudgetSetting;
use App\Models\Household;
use App\Models\HouseholdSetting;
use App\Models\PocketMoneyTransaction;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BudgetApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_parent_can_update_child_budget_setting(): void
    {
        [$parent, $child, $household] = $this->createHouseholdWithBudgetModule();
        Sanctum::actingAs($parent);

        $response = $this->patchJson("/api/budget/settings/{$child->id}", [
            'base_amount' => 35,
            'recurrence' => 'monthly',
            'reset_day' => 5,
            'allow_advances' => true,
            'max_advance_amount' => 15,
        ])->assertOk();

        $response
            ->assertJsonPath('setting.user_id', $child->id)
            ->assertJsonPath('setting.base_amount', 35.0)
            ->assertJsonPath('setting.recurrence', 'monthly')
            ->assertJsonPath('setting.reset_day', 5)
            ->assertJsonPath('setting.allow_advances', true)
            ->assertJsonPath('setting.max_advance_amount', 15.0);

        $this->assertDatabaseHas('budget_settings', [
            'household_id' => $household->id,
            'user_id' => $child->id,
            'base_amount' => 35,
            'recurrence' => 'monthly',
            'reset_day' => 5,
            'allow_advances' => true,
            'max_advance_amount' => 15,
        ]);
    }

    public function test_child_can_request_advance_when_allowed(): void
    {
        [$parent, $child, $household] = $this->createHouseholdWithBudgetModule();
        BudgetSetting::query()->create([
            'household_id' => $household->id,
            'user_id' => $child->id,
            'base_amount' => 20,
            'recurrence' => 'weekly',
            'reset_day' => 1,
            'allow_advances' => true,
            'max_advance_amount' => 12,
        ]);

        Sanctum::actingAs($child);

        $this->postJson('/api/budget/advances', [
            'amount' => 10,
            'comment' => 'Besoin pour la sortie scolaire',
        ])
            ->assertCreated()
            ->assertJsonPath('message', 'Demande d\'avance envoyée.')
            ->assertJsonPath('transaction.type', 'advance')
            ->assertJsonPath('transaction.status', 'pending')
            ->assertJsonPath('transaction.amount', 10.0)
            ->assertJsonPath('transaction.user_id', $child->id)
            ->assertJsonPath('transaction.request_kind', 'advance')
            ->assertJsonPath('transaction.payout_mode', null)
            ->assertJsonPath('transaction.user.id', $child->id);

        $this->assertDatabaseHas('pocket_money_transactions', [
            'household_id' => $household->id,
            'user_id' => $child->id,
            'type' => 'advance',
            'status' => 'pending',
            'amount' => 10,
        ]);

        $notification = UserNotification::query()
            ->where('user_id', $parent->id)
            ->where('type', 'budget_advance_requested')
            ->latest('id')
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame('Besoin pour la sortie scolaire', data_get($notification?->data, 'justification'));
    }

    public function test_budget_comment_cast_persists_meta_prefix_in_database(): void
    {
        [$parent, $child, $household] = $this->createHouseholdWithBudgetModule();
        $transaction = PocketMoneyTransaction::query()->create([
            'household_id' => $household->id,
            'user_id' => $child->id,
            'amount' => 9,
            'type' => 'advance',
            'status' => 'approved',
            'comment' => new BudgetComment('Achat urgent', BudgetComment::REQUEST_KIND_ADVANCE, BudgetComment::PAYOUT_MODE_IMMEDIATE),
        ]);

        $this->assertDatabaseHas('pocket_money_transactions', [
            'id' => $transaction->id,
            'comment' => "[budget-meta]request_kind=advance;payout_mode=immediate\nAchat urgent",
        ]);

        $refreshed = PocketMoneyTransaction::query()->findOrFail($transaction->id);
        $this->assertInstanceOf(BudgetComment::class, $refreshed->comment);
        $this->assertSame('Achat urgent', $refreshed->comment->displayComment);
        $this->assertSame(BudgetComment::REQUEST_KIND_ADVANCE, $refreshed->comment->requestKind);
        $this->assertSame(BudgetComment::PAYOUT_MODE_IMMEDIATE, $refreshed->comment->payoutMode);
    }

    public function test_parent_can_create_adjustment_and_child_is_notified(): void
    {
        [$parent, $child, $household] = $this->createHouseholdWithBudgetModule();
        Sanctum::actingAs($parent);

        $this->postJson('/api/budget/adjustments', [
            'user_id' => $child->id,
            'type' => 'bonus',
            'amount' => 7.5,
            'comment' => 'Aide en cuisine',
        ])
            ->assertCreated()
            ->assertJsonPath('transaction.type', 'bonus')
            ->assertJsonPath('transaction.user_id', $child->id)
            ->assertJsonPath('transaction.amount', 7.5);

        $this->assertDatabaseHas('pocket_money_transactions', [
            'household_id' => $household->id,
            'user_id' => $child->id,
            'type' => 'bonus',
            'status' => 'approved',
            'amount' => 7.5,
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'household_id' => $household->id,
            'user_id' => $child->id,
            'type' => 'budget_adjustment_added',
        ]);
    }

    public function test_parent_can_validate_payment_and_child_is_notified(): void
    {
        [$parent, $child, $household] = $this->createHouseholdWithBudgetModule();
        BudgetSetting::query()->create([
            'household_id' => $household->id,
            'user_id' => $child->id,
            'base_amount' => 20,
            'recurrence' => 'weekly',
            'reset_day' => 1,
            'allow_advances' => true,
            'max_advance_amount' => 10,
        ]);

        Sanctum::actingAs($parent);

        $this->postJson('/api/budget/payments', [
            'user_id' => $child->id,
            'action' => 'pay',
            'amount' => 20,
            'comment' => 'Versement semaine',
        ])
            ->assertCreated()
            ->assertJsonPath('transaction.type', 'allocation')
            ->assertJsonPath('transaction.status', 'approved')
            ->assertJsonPath('transaction.user_id', $child->id)
            ->assertJsonPath('transaction.amount', 20.0);

        $this->assertDatabaseHas('pocket_money_transactions', [
            'household_id' => $household->id,
            'user_id' => $child->id,
            'type' => 'allocation',
            'status' => 'approved',
            'amount' => 20,
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'household_id' => $household->id,
            'user_id' => $child->id,
            'type' => 'budget_payment_validated',
        ]);
    }

    public function test_child_request_advance_uses_household_context_header(): void
    {
        [$parentA, $child, $householdA] = $this->createHouseholdWithBudgetModule();
        BudgetSetting::query()->create([
            'household_id' => $householdA->id,
            'user_id' => $child->id,
            'base_amount' => 20,
            'recurrence' => 'weekly',
            'reset_day' => 1,
            'allow_advances' => true,
            'max_advance_amount' => 5,
        ]);

        $parentB = User::factory()->create([
            'must_change_password' => false,
        ]);
        $householdB = Household::query()->create([
            'name' => 'Foyer secondaire budget',
        ]);

        $householdB->users()->attach($parentB->id, ['role' => User::ROLE_PARENT]);
        $householdB->users()->attach($child->id, ['role' => User::ROLE_CHILD]);
        HouseholdSetting::query()->create([
            'household_id' => $householdB->id,
            'has_budget' => true,
        ]);
        BudgetSetting::query()->create([
            'household_id' => $householdB->id,
            'user_id' => $child->id,
            'base_amount' => 20,
            'recurrence' => 'weekly',
            'reset_day' => 1,
            'allow_advances' => true,
            'max_advance_amount' => 15,
        ]);

        Sanctum::actingAs($child);

        $this->withHeader('X-Household-Id', (string) $householdB->id)
            ->postJson('/api/budget/advances', [
                'amount' => 10,
                'comment' => 'Besoin pour le foyer secondaire',
            ])
            ->assertCreated()
            ->assertJsonPath('transaction.household_id', $householdB->id)
            ->assertJsonPath('transaction.user_id', $child->id)
            ->assertJsonPath('transaction.amount', 10.0);

        $this->assertDatabaseHas('pocket_money_transactions', [
            'household_id' => $householdB->id,
            'user_id' => $child->id,
            'type' => 'advance',
            'status' => 'pending',
            'amount' => 10,
        ]);

        $this->assertDatabaseMissing('pocket_money_transactions', [
            'household_id' => $householdA->id,
            'user_id' => $child->id,
            'type' => 'advance',
            'status' => 'pending',
            'amount' => 10,
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $parentB->id,
            'type' => 'budget_advance_requested',
        ]);
        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $parentA->id,
            'type' => 'budget_advance_requested',
        ]);
    }

    public function test_child_cannot_request_advance_above_limit(): void
    {
        [$parent, $child, $household] = $this->createHouseholdWithBudgetModule();
        BudgetSetting::query()->create([
            'household_id' => $household->id,
            'user_id' => $child->id,
            'base_amount' => 20,
            'recurrence' => 'weekly',
            'reset_day' => 1,
            'allow_advances' => true,
            'max_advance_amount' => 8,
        ]);

        Sanctum::actingAs($child);

        $this->postJson('/api/budget/advances', [
            'amount' => 12,
            'comment' => 'Je veux acheter un jeu',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('pocket_money_transactions', [
            'household_id' => $household->id,
            'user_id' => $child->id,
            'type' => 'advance',
            'status' => 'pending',
            'amount' => 12,
        ]);
    }

    public function test_parent_can_approve_pending_advance_with_adjusted_amount(): void
    {
        [$parent, $child, $household] = $this->createHouseholdWithBudgetModule();
        BudgetSetting::query()->create([
            'household_id' => $household->id,
            'user_id' => $child->id,
            'base_amount' => 20,
            'recurrence' => 'weekly',
            'reset_day' => 1,
            'allow_advances' => true,
            'max_advance_amount' => 10,
        ]);

        $request = PocketMoneyTransaction::query()->create([
            'household_id' => $household->id,
            'user_id' => $child->id,
            'amount' => 10,
            'type' => 'advance',
            'status' => 'pending',
            'comment' => 'Achat fournitures',
        ]);

        Sanctum::actingAs($parent);

        $this->postJson("/api/budget/advances/{$request->id}/review", [
            'status' => 'approved',
            'amount' => 8,
            'comment' => 'Montant ajusté',
        ])
            ->assertOk()
            ->assertJsonPath('transaction.id', $request->id)
            ->assertJsonPath('transaction.status', 'approved')
            ->assertJsonPath('transaction.amount', 8.0);

        $this->assertDatabaseHas('pocket_money_transactions', [
            'id' => $request->id,
            'status' => 'approved',
            'amount' => 8,
        ]);

        $notification = UserNotification::query()
            ->where('user_id', $child->id)
            ->where('type', 'budget_advance_reviewed')
            ->latest('id')
            ->first();

        $this->assertNotNull($notification);
        $justification = (string) data_get($notification?->data, 'justification', '');
        $this->assertStringContainsString('Achat fournitures', $justification);
        $this->assertStringContainsString('Montant ajust', $justification);
    }

    public function test_parent_from_other_household_cannot_review_advance(): void
    {
        [$parent, $child, $household] = $this->createHouseholdWithBudgetModule();
        BudgetSetting::query()->create([
            'household_id' => $household->id,
            'user_id' => $child->id,
            'base_amount' => 20,
            'recurrence' => 'weekly',
            'reset_day' => 1,
            'allow_advances' => true,
            'max_advance_amount' => 10,
        ]);

        $request = PocketMoneyTransaction::query()->create([
            'household_id' => $household->id,
            'user_id' => $child->id,
            'amount' => 6,
            'type' => 'advance',
            'status' => 'pending',
            'comment' => 'Sortie cinéma',
        ]);

        [$otherParent] = $this->createHouseholdWithBudgetModule(false);
        Sanctum::actingAs($otherParent);

        $this->postJson("/api/budget/advances/{$request->id}/review", [
            'status' => 'rejected',
            'comment' => 'Hors foyer',
        ])->assertNotFound();

        $this->assertDatabaseHas('pocket_money_transactions', [
            'id' => $request->id,
            'status' => 'pending',
        ]);
    }

    private function createHouseholdWithBudgetModule(bool $withChild = true): array
    {
        $parent = User::factory()->create([
            'must_change_password' => false,
        ]);

        $household = Household::query()->create([
            'name' => 'Foyer test budget',
        ]);

        $household->users()->attach($parent->id, [
            'role' => User::ROLE_PARENT,
        ]);

        HouseholdSetting::query()->create([
            'household_id' => $household->id,
            'has_budget' => true,
        ]);

        if (!$withChild) {
            return [$parent->fresh(), null, $household->fresh()];
        }

        $child = User::factory()->create([
            'must_change_password' => false,
        ]);

        $household->users()->attach($child->id, [
            'role' => User::ROLE_CHILD,
        ]);

        return [$parent->fresh(), $child->fresh(), $household->fresh()];
    }
}
