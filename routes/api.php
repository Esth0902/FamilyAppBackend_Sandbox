<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\HouseholdController;
use App\Http\Controllers\Api\MealPollController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\RecipeController;
use App\Http\Controllers\Api\ShoppingListController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'must.change.password'])->get('/user', function (Request $request) {
    return $request->user();
});

Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])
    ->middleware('throttle:6,1');
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])
    ->middleware('throttle:6,1');
Route::get('/dashboard', [HouseholdController::class, 'dashboard']);

Broadcast::routes([
    'middleware' => ['auth:sanctum', 'throttle:broadcast', 'must.change.password'],
]);

Route::middleware(['auth:sanctum', 'throttle:api', 'must.change.password'])->group(function () {

    // Auth
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/auth/change-initial-credentials', [AuthController::class, 'changeInitialCredentials']);
    Route::patch('/auth/profile', [AuthController::class, 'updateProfile']);
    Route::patch('/auth/households/{household}/nickname', [AuthController::class, 'updateHouseholdNickname']);

    // Households
    Route::post('/households', [HouseholdController::class, 'store']);
    Route::get('/households/config', [HouseholdController::class, 'config']);
    Route::get('/households/dietary-tags', [HouseholdController::class, 'dietaryTags']);
    Route::post('/households/dietary-tags', [HouseholdController::class, 'createDietaryTag']);
    Route::patch('/households/config', [HouseholdController::class, 'updateConfig']);
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
    Route::get('/meal-polls/active', [MealPollController::class, 'active']);
    Route::get('/meal-polls/history', [MealPollController::class, 'history']);
    Route::post('/meal-polls', [MealPollController::class, 'store']);
    Route::post('/meal-polls/{poll}/vote', [MealPollController::class, 'vote']);
    Route::post('/meal-polls/{poll}/votes/sync', [MealPollController::class, 'syncVotes']);
    Route::post('/meal-polls/{poll}/close', [MealPollController::class, 'close']);
    Route::post('/meal-polls/{poll}/validate', [MealPollController::class, 'validateResults']);

    // Notifications
    Route::get('/notifications/pending', [NotificationController::class, 'pending']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'read']);

    // Shopping Lists
    Route::get('/shopping-lists', [ShoppingListController::class, 'index']);
    Route::post('/shopping-lists', [ShoppingListController::class, 'storeList']);
    Route::get('/shopping-lists/{list}', [ShoppingListController::class, 'showList']);
    Route::delete('/shopping-lists/{list}', [ShoppingListController::class, 'destroyList']);
    Route::post('/shopping-lists/{list}/items', [ShoppingListController::class, 'addItem']);
    Route::patch('/shopping-lists/items/{item}', [ShoppingListController::class, 'updateItem']);
    Route::delete('/shopping-lists/items/{item}', [ShoppingListController::class, 'removeItem']);
});
