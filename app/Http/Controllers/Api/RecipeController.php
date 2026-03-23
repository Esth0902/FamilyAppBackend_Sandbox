<?php

namespace App\Http\Controllers\Api;

use App\Actions\Recipe\{
    DestroyRecipeAction,
    GetRecipesAction,
    PreviewAiRecipeAction,
    ShowRecipeAction,
    SuggestAiRecipesAction,
    ToggleRecipeBookmarkAction,
    UpsertRecipeAction
};
use App\Http\Controllers\Controller;
use App\Http\Requests\Recipe\{
    DestroyRecipeRequest,
    FinalizeAiRecipeStoreRequest,
    IndexRecipeRequest,
    PreviewAiRecipeRequest,
    ShowRecipeRequest,
    StoreRecipeRequest,
    SuggestAiRecipeRequest,
    ToggleBookmarkRequest,
    UpdateRecipeRequest
};
use App\Http\Resources\Recipe\{
    RecipeAiPayloadResource,
    RecipeMessageResource,
    RecipeMutationResource,
    RecipeResource
};
use App\Models\Recipe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RecipeController extends Controller
{
    public function index(IndexRecipeRequest $request, GetRecipesAction $getRecipesAction): AnonymousResourceCollection
    {
        $recipes = $getRecipesAction->execute(
            $request->household(),
            (string) $request->validated('scope', 'mine'),
            $request->has('q') ? (string) $request->input('q') : null,
            (int) $request->input('limit', 20),
        );

        return RecipeResource::collection($recipes);
    }

    public function show(ShowRecipeRequest $request, Recipe $recipe, ShowRecipeAction $showRecipeAction): RecipeResource
    {
        return RecipeResource::make($showRecipeAction->execute($recipe));
    }

    public function saveToMine(ToggleBookmarkRequest $request, Recipe $recipe, ToggleRecipeBookmarkAction $toggleRecipeBookmarkAction): JsonResponse
    {
        $updatedRecipe = $toggleRecipeBookmarkAction->execute($request->household(), $recipe, true, (int) $request->user()->id);
        return RecipeMutationResource::fromRecipe($updatedRecipe, 'Recette globale ajoutée à Mes recettes.')->response();
    }

    public function removeFromMine(ToggleBookmarkRequest $request, Recipe $recipe, ToggleRecipeBookmarkAction $toggleRecipeBookmarkAction): JsonResponse
    {
        $updatedRecipe = $toggleRecipeBookmarkAction->execute($request->household(), $recipe, false, (int) $request->user()->id);
        return RecipeMutationResource::fromRecipe($updatedRecipe, 'Recette globale retirée de Mes recettes.')->response();
    }

    public function suggestIdeas(SuggestAiRecipeRequest $request, SuggestAiRecipesAction $suggestAiRecipesAction): JsonResponse
    {
        $result = $suggestAiRecipesAction->execute($request->validated());
        return RecipeAiPayloadResource::make($result['payload'])->response()->setStatusCode($result['status']);
    }

    public function previewAiRecipe(PreviewAiRecipeRequest $request, PreviewAiRecipeAction $previewAiRecipeAction): JsonResponse
    {
        return RecipeAiPayloadResource::make($previewAiRecipeAction->execute($request->validated()))->response();
    }

    public function finalizeAiStore(FinalizeAiRecipeStoreRequest $request, UpsertRecipeAction $upsertRecipeAction): JsonResponse
    {
        $recipe = $upsertRecipeAction->createAi($request->validated());
        return RecipeResource::make($recipe)->response()->setStatusCode(201);
    }

    public function store(StoreRecipeRequest $request, UpsertRecipeAction $upsertRecipeAction): JsonResponse
    {
        $recipe = $upsertRecipeAction->upsertManual((int) $request->household()->id, $request->validated());
        return RecipeResource::make($recipe)->response()->setStatusCode(201);
    }

    public function update(UpdateRecipeRequest $request, Recipe $recipe, UpsertRecipeAction $upsertRecipeAction): JsonResponse
    {
        $updatedRecipe = $upsertRecipeAction->upsertManual((int) $request->household()->id, $request->validated(), $recipe);
        return RecipeResource::make($updatedRecipe)->response();
    }

    public function destroy(DestroyRecipeRequest $request, Recipe $recipe, DestroyRecipeAction $destroyRecipeAction): JsonResponse
    {
        $destroyRecipeAction->execute($recipe);
        return RecipeMessageResource::fromMessage('Recette supprimée')->response();
    }
}
