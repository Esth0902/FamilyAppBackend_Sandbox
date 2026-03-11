<?php

namespace Tests\Feature\Api;

use App\Models\Household;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShoppingListApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_child_can_add_manual_item_in_shopping_list_detail(): void
    {
        [$parent, $child, $household] = $this->createHouseholdWithParentAndChild();

        $list = ShoppingList::query()->create([
            'household_id' => $household->id,
            'title' => 'Courses semaine',
            'status' => 'active',
        ]);

        Sanctum::actingAs($child);

        $response = $this->postJson("/api/shopping-lists/{$list->id}/items", [
            'name' => 'Pommes',
            'quantity' => 4,
            'unit' => 'pièce',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('name', 'Pommes')
            ->assertJsonPath('is_manual_addition', true)
            ->assertJsonPath('created_by.id', $child->id)
            ->assertJsonPath('created_by.name', $child->name);

        $this->assertDatabaseHas('shopping_list_items', [
            'shopping_list_id' => $list->id,
            'name' => 'Pommes',
            'is_manual_addition' => true,
            'created_by_user_id' => $child->id,
        ]);
    }

    public function test_child_cannot_add_non_manual_item_to_shopping_list(): void
    {
        [, $child, $household] = $this->createHouseholdWithParentAndChild();

        $list = ShoppingList::query()->create([
            'household_id' => $household->id,
            'title' => 'Courses weekend',
            'status' => 'active',
        ]);

        Sanctum::actingAs($child);

        $this->postJson("/api/shopping-lists/{$list->id}/items", [
            'name' => 'Riz',
            'quantity' => 1,
            'unit' => 'kg',
            'is_manual_addition' => false,
        ])->assertForbidden();

        $this->assertDatabaseMissing('shopping_list_items', [
            'shopping_list_id' => $list->id,
            'name' => 'Riz',
        ]);
    }

    public function test_show_list_returns_created_by_and_child_manual_permission(): void
    {
        [$parent, $child, $household] = $this->createHouseholdWithParentAndChild();

        $list = ShoppingList::query()->create([
            'household_id' => $household->id,
            'title' => 'Courses famille',
            'status' => 'active',
        ]);

        $item = ShoppingListItem::query()->create([
            'shopping_list_id' => $list->id,
            'name' => 'Lait',
            'quantity' => '2',
            'unit' => 'L',
            'is_checked' => false,
            'is_manual_addition' => true,
            'created_by_user_id' => $parent->id,
        ]);

        Sanctum::actingAs($child);

        $this->getJson("/api/shopping-lists/{$list->id}")
            ->assertOk()
            ->assertJsonPath('can_manage', false)
            ->assertJsonPath('can_add_manual_items', true)
            ->assertJsonPath('list.items.0.id', $item->id)
            ->assertJsonPath('list.items.0.created_by.id', $parent->id)
            ->assertJsonPath('list.items.0.created_by.name', $parent->name);
    }

    private function createHouseholdWithParentAndChild(): array
    {
        $parent = User::factory()->create([
            'must_change_password' => false,
        ]);
        $child = User::factory()->create([
            'must_change_password' => false,
        ]);

        $household = Household::query()->create([
            'name' => 'Foyer test courses',
        ]);

        $household->users()->attach($parent->id, [
            'role' => User::ROLE_PARENT,
        ]);
        $household->users()->attach($child->id, [
            'role' => User::ROLE_CHILD,
        ]);

        return [$parent->fresh(), $child->fresh(), $household->fresh()];
    }
}

