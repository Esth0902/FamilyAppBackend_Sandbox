<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\HouseholdController;
use App\Http\Controllers\Api\MealPollController;
use App\Http\Controllers\Api\RecipeController;
use App\Http\Controllers\Api\ShoppingListController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);
Route::get('/dashboard', [HouseholdController::class, 'dashboard']);

Route::middleware(['auth:sanctum', 'throttle:10,1'])->group(function () {

    // Auth
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Households
    Route::post('/households', [HouseholdController::class, 'store']);
    Route::post('/household/members', [HouseholdController::class, 'addMember']);
    Route::get('/dashboard', [HouseholdController::class, 'dashboard']);

    // Recipes - IA (custom endpoints)
    Route::middleware('throttle:ai')->group(function () {
        Route::post('/recipes/suggest', [RecipeController::class, 'suggestIdeas']);
        Route::post('/recipes/preview-ai', [RecipeController::class, 'previewAiRecipe']);
        Route::post('/recipes/ai-store', [RecipeController::class, 'finalizeAiStore']);
    });

    // Recipes - CRUD (standard REST)
    Route::apiResource('recipes', RecipeController::class);

    // Meal Polls
    Route::apiResource('meal-polls', MealPollController::class);
    Route::post('/meal-polls/{poll}/vote', [MealPollController::class, 'vote']);
    Route::post('/meal-polls/{poll}/validate', [MealPollController::class, 'validateResults']);

    // Shopping List
    Route::get('/shopping-list', [ShoppingListController::class, 'index']);
    Route::post('/shopping-list/{list}/items', [ShoppingListController::class, 'addItem']);
    Route::patch('/shopping-list/items/{item}', [ShoppingListController::class, 'updateItem']);
    Route::delete('/shopping-list/items/{item}', [ShoppingListController::class, 'removeItem']);
});
