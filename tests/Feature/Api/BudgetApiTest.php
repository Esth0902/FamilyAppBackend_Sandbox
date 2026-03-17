<?php

namespace Tests\Feature\Api;

use App\Models\BudgetSetting;
use App\Models\Household;
use App\Models\HouseholdSetting;
use App\Models\PocketMoneyTransaction;
use App\Models\User;
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
            ->assertJsonPath('transaction.type', 'advance')
            ->assertJsonPath('transaction.status', 'pending')
            ->assertJsonPath('transaction.amount', 10.0)
            ->assertJsonPath('transaction.user_id', $child->id);

        $this->assertDatabaseHas('pocket_money_transactions', [
            'household_id' => $household->id,
            'user_id' => $child->id,
            'type' => 'advance',
            'status' => 'pending',
            'amount' => 10,
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

