<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesHouseholdContext;
use App\Http\Controllers\Controller;
use App\Models\Household;
use App\Models\Ingredient;
use App\Models\MealPlan;
use App\Models\MealSetting;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;
use App\Services\RealtimePublisher;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ShoppingListController extends Controller
{
    use ResolvesHouseholdContext;

    public function __construct(private readonly RealtimePublisher $realtimePublisher)
    {
    }

    public function index(Request $request): JsonResponse
    {
        [$household, $role] = $this->resolveHouseholdWithRole($request);
        $this->ensureShoppingModuleEnabled($household);

        $lists = ShoppingList::query()
            ->where('household_id', $household->id)
            ->withCount('items')
            ->orderByDesc('created_at')
            ->get()
            ->map(static function (ShoppingList $list): array {
                return [
                    'id' => (int)$list->id,
                    'title' => (string)$list->title,
                    'status' => (string)$list->status,
                    'items_count' => (int)($list->items_count ?? 0),
                    'created_at' => optional($list->created_at)?->toIso8601String(),
                    'updated_at' => optional($list->updated_at)?->toIso8601String(),
                ];
            })
            ->values();

        return response()->json([
            'can_manage' => $role === User::ROLE_PARENT,
            'lists' => $lists,
        ]);
    }

    public function storeList(Request $request): JsonResponse
    {
        [$household, $role] = $this->resolveHouseholdWithRole($request);
        $this->ensureShoppingModuleEnabled($household);
        $this->ensureParentRole($role);

        $validated = $request->validate([
            'title' => 'required|string|max:120',
        ]);

        $title = trim((string)$validated['title']);
        if ($title === '') {
            throw ValidationException::withMessages([
                'title' => ['Le nom de la liste est obligatoire.'],
            ]);
        }

        $list = ShoppingList::query()->create([
            'household_id' => $household->id,
            'title' => $title,
            'status' => 'active',
        ]);

        $this->realtimePublisher->publishHousehold(
            householdId: (int)$household->id,
            module: 'shopping_list',
            type: 'list.created',
            payload: [
                'list_id' => (int)$list->id,
                'title' => (string)$list->title,
                'actor_user_id' => (int)$request->user()->id,
            ],
        );

        return response()->json([
            'message' => 'Liste creee.',
            'list' => $list->loadCount('items'),
        ], 201);
    }

    public function showList(Request $request, ShoppingList $list): JsonResponse
    {
        [$household, $role] = $this->resolveHouseholdWithRole($request);
        $this->ensureShoppingModuleEnabled($household);
        $this->ensureListBelongsToHousehold($list, $household);
        $canManage = $role === User::ROLE_PARENT;

        [$fromDate, $toDate] = $this->resolvePlannedMealsRange();
        $plannedRecipes = $this->buildPlannedRecipeSuggestions((int)$household->id, $fromDate, $toDate, $list);

        return response()->json([
            'can_manage' => $canManage,
            'can_add_manual_items' => $canManage || $role === User::ROLE_CHILD,
            'list' => $list->load([
                'items' => fn($query) => $query->orderBy('is_checked')->orderBy('name'),
                'items.checkedBy:id,name',
                'items.createdBy:id,name',
            ]),
            'planned_meals_from' => $fromDate->toDateString(),
            'planned_meals_to' => $toDate->toDateString(),
            'planned_recipe_suggestions' => $plannedRecipes,
        ]);
    }

    public function destroyList(Request $request, ShoppingList $list): JsonResponse
    {
        [$household, $role] = $this->resolveHouseholdWithRole($request);
        $this->ensureShoppingModuleEnabled($household);
        $this->ensureListBelongsToHousehold($list, $household);
        $this->ensureParentRole($role);

        $listId = (int)$list->id;
        $title = (string)$list->title;
        $list->delete();

        $this->realtimePublisher->publishHousehold(
            householdId: (int)$household->id,
            module: 'shopping_list',
            type: 'list.deleted',
            payload: [
                'list_id' => $listId,
                'title' => $title,
                'actor_user_id' => (int)$request->user()->id,
            ],
        );

        return response()->json(['message' => 'Liste supprimee.']);
    }

    public function addItem(Request $request, ShoppingList $list): JsonResponse
    {
        [$household, $role] = $this->resolveHouseholdWithRole($request);
        $this->ensureShoppingModuleEnabled($household);
        $this->ensureListBelongsToHousehold($list, $household);

        $validated = $request->validate([
            'ingredient_id' => 'nullable|integer|exists:ingredients,id',
            'name' => 'nullable|string|max:255',
            'quantity' => 'nullable|numeric|min:0',
            'unit' => 'nullable|string|max:50',
            'is_manual_addition' => 'nullable|boolean',
        ]);

        if (empty($validated['ingredient_id']) && trim((string)($validated['name'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'name' => ['Le nom est obligatoire si aucun ingredient n est fourni.'],
            ]);
        }

        $normalizedPayload = $this->normalizeIncomingItemPayload($validated);
        $isManual = array_key_exists('is_manual_addition', $validated)
            ? (bool)$validated['is_manual_addition']
            : true;

        if ($role !== User::ROLE_PARENT && $role !== User::ROLE_CHILD) {
            abort(403, 'Rôle non autorisé pour modifier la liste de courses.');
        }
        if ($role === User::ROLE_CHILD && !$isManual) {
            abort(403, 'Un enfant peut uniquement ajouter un élément manuel.');
        }

        $item = $this->upsertListItem(
            $list,
            $normalizedPayload,
            $isManual,
            (int)$request->user()->id
        );

        $this->realtimePublisher->publishHousehold(
            householdId: (int)$household->id,
            module: 'shopping_list',
            type: 'item.upserted',
            payload: [
                'list_id' => (int)$list->id,
                'item_id' => (int)$item->id,
                'actor_user_id' => (int)$request->user()->id,
            ],
        );

        return response()->json($item->load('checkedBy:id,name', 'createdBy:id,name'), 201);
    }

    public function updateItem(Request $request, ShoppingListItem $item): JsonResponse
    {
        [$household] = $this->resolveHouseholdWithRole($request);
        $this->ensureItemBelongsToHousehold($item, $household);

        $validated = $request->validate([
            'is_checked' => 'nullable|boolean',
            'quantity' => 'nullable|string|max:50',
            'name' => 'nullable|string|max:255',
        ]);

        $updates = [];
        if (array_key_exists('is_checked', $validated)) {
            $updates['is_checked'] = (bool)$validated['is_checked'];
            if (Schema::hasColumn('shopping_list_items', 'checked_by_user_id')) {
                $updates['checked_by_user_id'] = $updates['is_checked']
                    ? (int)$request->user()->id
                    : null;
            }
        }
        if (array_key_exists('quantity', $validated)) {
            $updates['quantity'] = trim((string)$validated['quantity']);
        }
        if (array_key_exists('name', $validated)) {
            $updates['name'] = trim((string)$validated['name']);
        }

        if (count($updates) === 0) {
            return response()->json($item->load('checkedBy:id,name', 'createdBy:id,name'));
        }

        $item->update($updates);

        $this->realtimePublisher->publishHousehold(
            householdId: (int)$household->id,
            module: 'shopping_list',
            type: 'item.updated',
            payload: [
                'list_id' => (int)$item->shopping_list_id,
                'item_id' => (int)$item->id,
                'actor_user_id' => (int)$request->user()->id,
            ],
        );

        return response()->json($item->fresh()->load('checkedBy:id,name', 'createdBy:id,name'));
    }

    public function removeItem(Request $request, ShoppingListItem $item): JsonResponse
    {
        [$household, $role] = $this->resolveHouseholdWithRole($request);
        $this->ensureItemBelongsToHousehold($item, $household);
        $this->ensureParentRole($role);

        $itemId = (int)$item->id;
        $listId = (int)$item->shopping_list_id;
        $item->delete();

        $this->realtimePublisher->publishHousehold(
            householdId: (int)$household->id,
            module: 'shopping_list',
            type: 'item.deleted',
            payload: [
                'list_id' => $listId,
                'item_id' => $itemId,
                'actor_user_id' => (int)$request->user()->id,
            ],
        );

        return response()->json(['message' => 'Element supprime']);
    }

    private function ensureShoppingModuleEnabled(Household $household): void
    {
        $mealSettings = MealSetting::query()
            ->where('household_id', $household->id)
            ->first();

        if ($mealSettings && !$mealSettings->enable_shopping_list) {
            abort(403, 'Le module liste de courses est desactive pour ce foyer.');
        }
    }

    private function ensureListBelongsToHousehold(ShoppingList $list, Household $household): void
    {
        if ((int)$list->household_id !== (int)$household->id) {
            abort(404, 'Liste introuvable.');
        }
    }

    private function ensureItemBelongsToHousehold(ShoppingListItem $item, Household $household): void
    {
        $list = $item->shoppingList()->first();
        if (!$list || (int)$list->household_id !== (int)$household->id) {
            abort(404, 'Element introuvable.');
        }
    }

    private function normalizeIncomingItemPayload(array $validated): array
    {
        $ingredientId = isset($validated['ingredient_id']) ? (int)$validated['ingredient_id'] : null;
        $ingredient = $ingredientId ? Ingredient::find($ingredientId) : null;

        $name = trim((string)($validated['name'] ?? ''));
        if ($name === '' && $ingredient) {
            $name = (string)$ingredient->name;
        }

        $unit = trim((string)($validated['unit'] ?? ''));
        $quantity = isset($validated['quantity'])
            ? $this->formatQuantity((float)$validated['quantity'])
            : '';

        return [
            'ingredient_id' => $ingredient?->id,
            'name' => $name,
            'unit' => $unit,
            'quantity' => $quantity,
        ];
    }

    private function upsertListItem(
        ShoppingList $list,
        array $payload,
        bool $isManualAddition,
        int $actorUserId
    ): ShoppingListItem
    {
        $existingItem = null;

        if (!empty($payload['ingredient_id'])) {
            $existingItem = ShoppingListItem::query()
                ->where('shopping_list_id', $list->id)
                ->where('ingredient_id', (int)$payload['ingredient_id'])
                ->first();
        } else {
            $normalizedKey = $this->normalizeNameUnitKey((string)$payload['name'], (string)$payload['unit']);
            $existingItem = ShoppingListItem::query()
                ->where('shopping_list_id', $list->id)
                ->whereNull('ingredient_id')
                ->get()
                ->first(function (ShoppingListItem $item) use ($normalizedKey): bool {
                    return $this->normalizeNameUnitKey((string)$item->name, (string)($item->unit ?? '')) === $normalizedKey;
                });
        }

        if ($existingItem) {
            $mergedQuantity = $this->mergeQuantities((string)($existingItem->quantity ?? ''), (string)($payload['quantity'] ?? ''));
            $existingItem->update([
                'name' => $payload['name'],
                'unit' => $payload['unit'],
                'quantity' => $mergedQuantity,
                'is_manual_addition' => (bool)$existingItem->is_manual_addition && $isManualAddition,
                'created_by_user_id' => (int)($existingItem->created_by_user_id ?? 0) > 0
                    ? (int)$existingItem->created_by_user_id
                    : $actorUserId,
            ]);

            return $existingItem->fresh();
        }

        return $list->items()->create([
            'ingredient_id' => $payload['ingredient_id'],
            'name' => $payload['name'],
            'quantity' => $payload['quantity'],
            'unit' => $payload['unit'],
            'is_checked' => false,
            'is_manual_addition' => $isManualAddition,
            'created_by_user_id' => $actorUserId,
        ]);
    }

    private function mergeQuantities(string $existing, string $incoming): string
    {
        $existingFloat = is_numeric($existing) ? (float)$existing : null;
        $incomingFloat = is_numeric($incoming) ? (float)$incoming : null;

        if ($existingFloat !== null && $incomingFloat !== null) {
            return $this->formatQuantity($existingFloat + $incomingFloat);
        }

        if ($incoming !== '') {
            return $incoming;
        }

        return $existing;
    }

    private function formatQuantity(float $value): string
    {
        $rounded = round($value, 2);
        if ((int)$rounded === (float)$rounded) {
            return (string)(int)$rounded;
        }

        return rtrim(rtrim(number_format($rounded, 2, '.', ''), '0'), '.');
    }

    private function normalizeNameUnitKey(string $name, string $unit): string
    {
        return mb_strtolower(trim($name)) . '|' . mb_strtolower(trim($unit));
    }

    private function resolvePlannedMealsRange(): array
    {
        $from = now()->startOfDay();
        $to = (clone $from)->addDays(13)->endOfDay();
        return [$from, $to];
    }

    private function buildPlannedRecipeSuggestions(
        int $householdId,
        Carbon $fromDate,
        Carbon $toDate,
        ShoppingList $list
    ): array {
        $mealPlans = MealPlan::query()
            ->where('household_id', $householdId)
            ->whereBetween('date', [$fromDate->toDateString(), $toDate->toDateString()])
            ->with(['items.recipe.ingredients'])
            ->orderBy('date')
            ->get();

        $existingItems = $list->items()->get(['ingredient_id', 'name', 'unit']);
        $existingIngredientIds = $existingItems
            ->whereNotNull('ingredient_id')
            ->pluck('ingredient_id')
            ->map(fn($id) => (int)$id)
            ->all();
        $existingNameUnitKeys = $existingItems
            ->whereNull('ingredient_id')
            ->map(fn(ShoppingListItem $item) => $this->normalizeNameUnitKey((string)$item->name, (string)($item->unit ?? '')))
            ->all();

        $mealTypeOrder = ['matin' => 0, 'midi' => 1, 'soir' => 2];
        $recipes = [];

        foreach ($mealPlans as $mealPlan) {
            foreach ($mealPlan->items as $mealPlanItem) {
                $recipe = $mealPlanItem->recipe;
                if (!$recipe) {
                    continue;
                }

                $baseServings = max(1, (int)($recipe->base_servings ?? 1));
                $targetServings = max(1, (int)($mealPlanItem->servings ?? $baseServings));
                $scale = $targetServings / $baseServings;

                $ingredients = $recipe->ingredients
                    ->map(function ($ingredient) use ($scale, $existingIngredientIds, $existingNameUnitKeys): array {
                        $name = trim((string)($ingredient->name ?? 'ingredient'));
                        $unit = trim((string)($ingredient->pivot->unit ?? ''));
                        $ingredientId = $ingredient->id ? (int)$ingredient->id : null;
                        $baseQty = (float)($ingredient->pivot->quantity ?? 0);
                        $scaledQty = round($baseQty * $scale, 2);

                        $alreadyInList = $ingredientId
                            ? in_array($ingredientId, $existingIngredientIds, true)
                            : in_array($this->normalizeNameUnitKey($name, $unit), $existingNameUnitKeys, true);

                        return [
                            'ingredient_id' => $ingredientId,
                            'name' => $name,
                            'quantity' => $this->formatQuantity($scaledQty),
                            'unit' => $unit,
                            'already_in_list' => $alreadyInList,
                        ];
                    })
                    ->values();

                $recipes[] = [
                    'meal_plan_id' => (int)$mealPlan->id,
                    'recipe_id' => (int)$recipe->id,
                    'recipe_title' => (string)$recipe->title,
                    'date' => $mealPlan->date ? Carbon::parse((string)$mealPlan->date)->toDateString() : null,
                    'meal_type' => (string)$mealPlan->meal_type,
                    'servings' => $targetServings,
                    'already_in_list' => $ingredients->count() > 0
                        ? $ingredients->every(static fn(array $ingredient): bool => (bool)$ingredient['already_in_list'])
                        : false,
                    'ingredients' => $ingredients->all(),
                    'meal_type_order' => $mealTypeOrder[(string)$mealPlan->meal_type] ?? 99,
                ];
            }
        }

        return collect($recipes)
            ->sortBy([
                ['date', 'asc'],
                ['meal_type_order', 'asc'],
                ['recipe_title', 'asc'],
            ])
            ->values()
            ->map(function (array $recipe): array {
                unset($recipe['meal_type_order']);
                return $recipe;
            })
            ->all();
    }
}
