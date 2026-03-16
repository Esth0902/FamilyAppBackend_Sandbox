<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BudgetController;
use App\Http\Controllers\Api\CalendarController;
use App\Http\Controllers\Api\HouseholdController;
use App\Http\Controllers\Api\MealPollController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\RecipeController;
use App\Http\Controllers\Api\ShoppingListController;
use App\Http\Controllers\Api\TaskController;
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
    Route::get('/household/members', [HouseholdController::class, 'members']);
    Route::post('/household/members', [HouseholdController::class, 'addMember']);
    Route::patch('/household/members/{member}', [HouseholdController::class, 'updateMember']);
    Route::delete('/household/members/{member}', [HouseholdController::class, 'deleteMember']);
    Route::post('/household/members/{member}/temporary-access', [HouseholdController::class, 'refreshMemberTemporaryAccess']);
    Route::get('/dashboard', [HouseholdController::class, 'dashboard']);

    // Recipes - IA (custom endpoints)
    Route::middleware('throttle:ai')->group(function () {
        Route::post('/recipes/suggest', [RecipeController::class, 'suggestIdeas']);
        Route::post('/recipes/preview-ai', [RecipeController::class, 'previewAiRecipe']);
        Route::post('/recipes/ai-store', [RecipeController::class, 'finalizeAiStore']);
    });

    // Recipes - CRUD (standard REST)
    Route::apiResource('recipes', RecipeController::class);
    Route::post('/recipes/{recipe}/save', [RecipeController::class, 'saveToMine']);
    Route::delete('/recipes/{recipe}/save', [RecipeController::class, 'removeFromMine']);

    // Meal Polls
    Route::get('/meal-polls/active', [MealPollController::class, 'active']);
    Route::get('/meal-polls/history', [MealPollController::class, 'history']);
    Route::post('/meal-polls', [MealPollController::class, 'store']);
    Route::patch('/meal-polls/{poll}', [MealPollController::class, 'update']);
    Route::post('/meal-polls/{poll}/vote', [MealPollController::class, 'vote']);
    Route::post('/meal-polls/{poll}/votes/sync', [MealPollController::class, 'syncVotes']);
    Route::post('/meal-polls/{poll}/close', [MealPollController::class, 'close']);
    Route::post('/meal-polls/{poll}/validate', [MealPollController::class, 'validateResults']);

    // Tasks
    Route::get('/tasks/board', [TaskController::class, 'board']);
    Route::post('/tasks/templates', [TaskController::class, 'storeTemplate']);
    Route::patch('/tasks/templates/{template}', [TaskController::class, 'updateTemplate']);
    Route::delete('/tasks/templates/{template}', [TaskController::class, 'destroyTemplate']);
    Route::post('/tasks/instances', [TaskController::class, 'storeInstance']);
    Route::patch('/tasks/instances/{instance}', [TaskController::class, 'updateInstance']);
    Route::post('/tasks/instances/{instance}/validate', [TaskController::class, 'validateInstance']);
    Route::post('/tasks/instances/{instance}/reassignment-request', [TaskController::class, 'requestInstanceReassignment']);

    // Calendar
    Route::get('/calendar/board', [CalendarController::class, 'board']);
    Route::post('/calendar/events', [CalendarController::class, 'storeEvent']);
    Route::patch('/calendar/events/{event}', [CalendarController::class, 'updateEvent']);
    Route::delete('/calendar/events/{event}', [CalendarController::class, 'destroyEvent']);
    Route::post('/calendar/meal-plan', [CalendarController::class, 'storeMealPlan']);
    Route::patch('/calendar/meal-plan/{mealPlan}', [CalendarController::class, 'updateMealPlan']);
    Route::delete('/calendar/meal-plan/{mealPlan}', [CalendarController::class, 'destroyMealPlan']);

    // Notifications
    Route::get('/notifications/pending', [NotificationController::class, 'pending']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'read']);
    Route::post('/notifications/{notification}/household-invite-response', [NotificationController::class, 'respondHouseholdInvite']);
    Route::post('/notifications/{notification}/task-reassignment-response', [NotificationController::class, 'respondTaskReassignmentInvite']);

    // Shopping Lists
    Route::get('/shopping-lists', [ShoppingListController::class, 'index']);
    Route::post('/shopping-lists', [ShoppingListController::class, 'storeList']);
    Route::get('/shopping-lists/{list}', [ShoppingListController::class, 'showList']);
    Route::delete('/shopping-lists/{list}', [ShoppingListController::class, 'destroyList']);
    Route::post('/shopping-lists/{list}/items', [ShoppingListController::class, 'addItem']);
    Route::patch('/shopping-lists/items/{item}', [ShoppingListController::class, 'updateItem']);
    Route::delete('/shopping-lists/items/{item}', [ShoppingListController::class, 'removeItem']);

    // Budget
    Route::get('/budget/board', [BudgetController::class, 'board']);
    Route::patch('/budget/settings/{user}', [BudgetController::class, 'updateSetting']);
    Route::post('/budget/payments', [BudgetController::class, 'validatePayment']);
    Route::post('/budget/advances', [BudgetController::class, 'requestAdvance']);
    Route::post('/budget/advances/{transaction}/review', [BudgetController::class, 'reviewAdvance']);
});
