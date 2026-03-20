<?php

namespace Tests\Feature\Api;

use App\Models\Household;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;
use App\Services\EmbeddingService;
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

    public function test_add_item_merges_semantically_with_existing_unchecked_item(): void
    {
        [$parent, $child, $household] = $this->createHouseholdWithParentAndChild();

        $list = ShoppingList::query()->create([
            'household_id' => $household->id,
            'title' => 'Courses semaine',
            'status' => 'active',
        ]);

        $existingItem = ShoppingListItem::query()->create([
            'shopping_list_id' => $list->id,
            'name' => 'Banane',
            'quantity' => '1',
            'unit' => 'piece',
            'is_checked' => false,
            'is_manual_addition' => true,
            'created_by_user_id' => $parent->id,
        ]);

        $this->app->instance(EmbeddingService::class, new class((int) $existingItem->id) extends EmbeddingService
        {
            public function __construct(private readonly int $matchId)
            {
            }

            public function generateVector(string $text, int $dimensions = 512): ?array
            {
                return array_fill(0, $dimensions, 0.01);
            }

            public function serializeVector(?array $vector): ?string
            {
                if (!is_array($vector) || count($vector) === 0) {
                    return null;
                }

                return '[' . implode(',', array_map(
                    static fn($value): string => rtrim(rtrim(sprintf('%.8F', (float) $value), '0'), '.'),
                    $vector
                )) . ']';
            }

            public function findClosestSemanticMatch(
                string $table,
                array $vector,
                ?string $whereClause = null,
                array $bindings = [],
                array $columns = ['id']
            ): ?array {
                return [
                    'id' => $this->matchId,
                    'distance' => 0.02,
                    'unit' => 'piece',
                ];
            }
        });

        Sanctum::actingAs($child);

        $response = $this->postJson("/api/shopping-lists/{$list->id}/items", [
            'name' => 'Bananes',
            'quantity' => 2,
            'unit' => 'piece',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('id', $existingItem->id)
            ->assertJsonPath('quantity', '3');

        $this->assertDatabaseCount('shopping_list_items', 1);
        $this->assertDatabaseHas('shopping_list_items', [
            'id' => $existingItem->id,
            'quantity' => '3',
        ]);
    }

    public function test_toggle_item_endpoint_sets_checked_by_user(): void
    {
        [$parent, $child, $household] = $this->createHouseholdWithParentAndChild();

        $list = ShoppingList::query()->create([
            'household_id' => $household->id,
            'title' => 'Courses semaine',
            'status' => 'active',
        ]);

        $item = ShoppingListItem::query()->create([
            'shopping_list_id' => $list->id,
            'name' => 'Lait',
            'quantity' => '1',
            'unit' => 'L',
            'is_checked' => false,
            'is_manual_addition' => true,
            'created_by_user_id' => $parent->id,
        ]);

        Sanctum::actingAs($child);

        $this->patchJson("/api/shopping-lists/items/{$item->id}/toggle", [
            'is_checked' => true,
        ])
            ->assertOk()
            ->assertJsonPath('id', $item->id)
            ->assertJsonPath('is_checked', true)
            ->assertJsonPath('checked_by.id', $child->id)
            ->assertJsonPath('checked_by.name', $child->name);

        $this->assertDatabaseHas('shopping_list_items', [
            'id' => $item->id,
            'is_checked' => true,
            'checked_by_user_id' => $child->id,
        ]);
    }

    public function test_parent_can_clear_checked_items_in_list(): void
    {
        [$parent, , $household] = $this->createHouseholdWithParentAndChild();

        $list = ShoppingList::query()->create([
            'household_id' => $household->id,
            'title' => 'Courses semaine',
            'status' => 'active',
        ]);

        $checkedItem = ShoppingListItem::query()->create([
            'shopping_list_id' => $list->id,
            'name' => 'Lait',
            'quantity' => '1',
            'unit' => 'L',
            'is_checked' => true,
            'is_manual_addition' => true,
            'created_by_user_id' => $parent->id,
            'checked_by_user_id' => $parent->id,
        ]);

        $uncheckedItem = ShoppingListItem::query()->create([
            'shopping_list_id' => $list->id,
            'name' => 'Pain',
            'quantity' => '1',
            'unit' => 'piece',
            'is_checked' => false,
            'is_manual_addition' => true,
            'created_by_user_id' => $parent->id,
        ]);

        Sanctum::actingAs($parent);

        $this->deleteJson("/api/shopping-lists/{$list->id}/items/checked")
            ->assertOk()
            ->assertJsonPath('deleted_count', 1);

        $this->assertDatabaseMissing('shopping_list_items', [
            'id' => $checkedItem->id,
        ]);
        $this->assertDatabaseHas('shopping_list_items', [
            'id' => $uncheckedItem->id,
        ]);
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
