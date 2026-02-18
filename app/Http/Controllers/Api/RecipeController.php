<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\MealSetting;
use App\Models\Recipe;
use App\Services\AiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class RecipeController extends Controller
{
    use AuthorizesRequests;

    protected AiService $aiService;

    private const RECIPE_TYPES = [
        'petit-déjeuner', 'entrée', 'plat principal', 'dessert', 'collation', 'boisson', 'autre'
    ];

    private const INGREDIENT_CATEGORIES = [
        'fruits et légumes',
        'boucherie',
        'poissonnerie',
        'crèmerie',
        'épicerie salée',
        'épicerie sucrée',
        'boissons',
        'surgelés',
        'entretien et hygiène',
        'autre',
    ];

    public function __construct(AiService $aiService)
    {
        $this->aiService = $aiService;
    }

    public function index(Request $request)
    {
        $userId = Auth::id();

        $recipes = Recipe::with(['ingredients', 'household.mealSettings'])
            ->whereHas('household.users', function ($query) use ($userId) {
                $query->where('users.id', $userId);
            })
            ->orderBy('title', 'asc')
            ->get();

        $formattedRecipes = $recipes->map(function (Recipe $recipe) use ($request) {
            $targetServings = $this->resolveTargetServings($request, $recipe);
            return $this->applyServingsView($recipe, $targetServings);
        })->values();

        return response()->json($formattedRecipes);
    }

    public function show(Request $request, $id)
    {
        $recipe = Recipe::with(['ingredients', 'household.mealSettings'])->find($id);

        if (!$recipe) {
            return response()->json(['message' => 'Recette introuvable'], 404);
        }

        $this->authorize('view', $recipe);

        $targetServings = $this->resolveTargetServings($request, $recipe);
        return response()->json($this->applyServingsView($recipe, $targetServings));
    }

    public function suggestIdeas(Request $request)
    {
        $validated = $request->validate([
            'preferences' => 'nullable|string|max:500',
            'count' => 'nullable|integer|max:5',
            'intent' => 'nullable|string|in:ideas,specific'
        ]);

        $text = $validated['preferences'] ?? '';
        $intent = $validated['intent'] ?? 'ideas';
        $count = $validated['count'] ?? 3;

        if ($intent === 'specific') {
            $recipe = $this->aiService->getFullRecipeDetails($text);

            if (empty($recipe)) {
                return response()->json(['message' => 'Impossible de générer la recette'], 422);
            }

            return response()->json(['type' => 'single', 'data' => $recipe]);
        }

        $ideas = $this->aiService->suggestMealIdeas($count, $text);

        return response()->json(['type' => 'list', 'data' => $ideas]);
    }

    public function previewAiRecipe(Request $request)
    {
        $request->validate(['title' => 'required|string|max:255']);

        $details = $this->aiService->getFullRecipeDetails($request->title);

        return response()->json($details);
    }

    public function finalizeAiStore(Request $request)
    {
        $validated = $request->validate([
            'household_id' => 'required|exists:households,id',
            'title' => 'required|string|max:255',
            'type' => 'required|string|in:' . implode(',', self::RECIPE_TYPES),
            'description' => 'required|string',
            'instructions' => 'required|string',

            'ingredients' => 'required|array|min:1',
            'ingredients.*.name' => 'required|string|max:255',
            'ingredients.*.unit' => 'nullable|string|max:50',
            'ingredients.*.quantity' => 'required|numeric',
            'ingredients.*.category' => 'nullable|string|in:' . implode(',', self::INGREDIENT_CATEGORIES),
        ]);

        $this->authorize('create', [Recipe::class, (int)$validated['household_id']]);

        return DB::transaction(function () use ($validated) {

            $recipe = Recipe::create([
                'household_id' => (int)$validated['household_id'],
                'title' => $validated['title'],
                'type' => $validated['type'],
                'description' => $validated['description'],
                'instructions' => $validated['instructions'],
                'is_ai_generated' => true,
                'base_servings' => 1,
            ]);

            $syncData = [];

            foreach ($validated['ingredients'] as $item) {
                $name = $this->normName($item['name']);
                $category = $this->normCategory($item['category'] ?? 'autre');

                $ingredient = Ingredient::firstOrCreate(
                    ['name' => $name],
                    ['category' => $category]
                );

                $qty = (float)$item['quantity'];
                if (isset($syncData[$ingredient->id])) {
                    $syncData[$ingredient->id]['quantity'] += $qty;
                } else {
                    $syncData[$ingredient->id] = [
                        'quantity' => $qty,
                        'unit' => $item['unit'] ?? '',
                    ];
                }
            }

            $recipe->ingredients()->sync($syncData);

            return response()->json($recipe->load('ingredients'), 201);
        });
    }

    public function store(Request $request)
    {
        $household = $request->user()->households()->first();
        if (!$household) {
            return response()->json(['message' => 'Aucun foyer associé'], 403);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'type' => 'required|string|in:' . implode(',', self::RECIPE_TYPES),
            'description' => 'nullable|string',
            'instructions' => 'nullable|string',

            'ingredients' => 'required|array|min:1',
            'ingredients.*.name' => 'required|string|max:255',
            'ingredients.*.quantity' => 'nullable|numeric',
            'ingredients.*.unit' => 'nullable|string|max:50',
            'ingredients.*.category' => 'nullable|string|in:' . implode(',', self::INGREDIENT_CATEGORIES),
            'base_servings' => 'nullable|integer|min:1|max:30',
        ]);

        $this->authorize('create', [Recipe::class, $household->id]);

        return DB::transaction(function () use ($validated, $household) {

            $recipe = Recipe::create([
                'household_id' => $household->id,
                'title' => $validated['title'],
                'type' => $validated['type'],
                'description' => $validated['description'] ?? '',
                'instructions' => $validated['instructions'] ?? '',
                'is_ai_generated' => false,
                'base_servings' => (int)($validated['base_servings'] ?? $this->resolveHouseholdDefaultServings((int)$household->id)),
            ]);

            $syncData = [];
            foreach ($validated['ingredients'] as $item) {
                $name = $this->normName($item['name']);
                $category = $this->normCategory($item['category'] ?? 'autre');

                $ingredient = Ingredient::firstOrCreate(
                    ['name' => $name],
                    ['category' => $category]
                );

                $qty = (float)($item['quantity'] ?? 0);
                if (isset($syncData[$ingredient->id])) {
                    $syncData[$ingredient->id]['quantity'] += $qty;
                } else {
                    $syncData[$ingredient->id] = [
                        'quantity' => $qty,
                        'unit' => $item['unit'] ?? '',
                    ];
                }
            }

            $recipe->ingredients()->sync($syncData);

            return response()->json($recipe->load('ingredients'), 201);
        });
    }

    public function update(Request $request, $id)
    {
        $recipe = Recipe::with('ingredients')->findOrFail($id);

        $this->authorize('update', $recipe);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'type' => 'required|string|in:' . implode(',', self::RECIPE_TYPES),
            'description' => 'nullable|string',
            'instructions' => 'nullable|string',

            'ingredients' => 'required|array|min:1',
            'ingredients.*.name' => 'required|string|max:255',
            'ingredients.*.unit' => 'nullable|string|max:50',
            'ingredients.*.quantity' => 'nullable|numeric',
            'ingredients.*.category' => 'nullable|string|in:' . implode(',', self::INGREDIENT_CATEGORIES),
            'base_servings' => 'nullable|integer|min:1|max:30',
        ]);

        return DB::transaction(function () use ($recipe, $validated) {

            $recipe->update([
                'title' => $validated['title'],
                'type' => $validated['type'],
                'description' => $validated['description'] ?? '',
                'instructions' => $validated['instructions'] ?? '',
                'base_servings' => (int)($validated['base_servings'] ?? $recipe->base_servings ?? 1),
            ]);

            $syncData = [];
            foreach ($validated['ingredients'] as $item) {
                $name = $this->normName($item['name']);
                $category = $this->normCategory($item['category'] ?? 'autre');

                $ingredient = Ingredient::firstOrCreate(
                    ['name' => $name],
                    ['category' => $category]
                );

                $qty = (float)($item['quantity'] ?? 0);
                if (isset($syncData[$ingredient->id])) {
                    $syncData[$ingredient->id]['quantity'] += $qty;
                } else {
                    $syncData[$ingredient->id] = [
                        'quantity' => $qty,
                        'unit' => $item['unit'] ?? '',
                    ];
                }
            }

            $recipe->ingredients()->sync($syncData);

            return response()->json($recipe->load('ingredients'));
        });
    }

    public function destroy($id)
    {
        $recipe = Recipe::find($id);

        if (!$recipe) {
            return response()->json(['message' => 'Recette introuvable'], 404);
        }

        $this->authorize('delete', $recipe);

        return DB::transaction(function () use ($recipe) {
            $recipe->ingredients()->detach();
            $recipe->delete();

            return response()->json(['message' => 'Recette supprimée']);
        });
    }

    private function normName(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = preg_replace('/\([^)]*\)/u', '', $name); // enlève parenthèses
        $name = preg_replace('/[.,;:!?"]/u', '', $name);
        $name = preg_replace('/\s+/u', ' ', $name);
        return trim($name);
    }

    private function normCategory(string $category): string
    {
        $category = mb_strtolower(trim($category));

        $aliases = [
            'epicerie salee' => 'épicerie salée',
            'epicerie sucree' => 'épicerie sucrée',
            'fruit et legumes' => 'fruits et légumes',
        ];
        $category = $aliases[$category] ?? $category;

        return in_array($category, self::INGREDIENT_CATEGORIES, true) ? $category : 'autre';
    }

    private function resolveTargetServings(Request $request, Recipe $recipe): int
    {
        $queryServings = $request->query('servings');
        if (is_numeric($queryServings)) {
            $parsed = (int)$queryServings;
            if ($parsed >= 1 && $parsed <= 30) {
                return $parsed;
            }
        }

        $householdDefault = (int)($recipe->household?->mealSettings?->default_servings ?? 0);
        if ($householdDefault >= 1) {
            return $householdDefault;
        }

        $baseServings = (int)($recipe->base_servings ?? 0);
        return $baseServings >= 1 ? $baseServings : 1;
    }

    private function resolveHouseholdDefaultServings(int $householdId): int
    {
        $defaultServings = (int)(MealSetting::query()
            ->where('household_id', $householdId)
            ->value('default_servings') ?? 0);
        return $defaultServings >= 1 ? $defaultServings : 1;
    }

    private function applyServingsView(Recipe $recipe, int $targetServings): Recipe
    {
        $baseServings = max(1, (int)($recipe->base_servings ?? 1));
        $targetServings = max(1, $targetServings);
        $scaleFactor = $targetServings / $baseServings;

        $recipe->setAttribute('display_servings', $targetServings);
        $recipe->setAttribute('scale_factor', $scaleFactor);

        $recipe->ingredients->each(function (Ingredient $ingredient) use ($scaleFactor): void {
            $baseQuantity = (float)($ingredient->pivot->quantity ?? 0);
            $ingredient->setAttribute('base_quantity', $baseQuantity);
            $ingredient->setAttribute('scaled_quantity', round($baseQuantity * $scaleFactor, 2));
        });

        return $recipe;
    }
}
