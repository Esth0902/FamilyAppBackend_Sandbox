<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BudgetController;
use App\Http\Controllers\Api\CalendarController;
use App\Http\Controllers\Api\HouseholdConnectionController;
use App\Http\Controllers\Api\HouseholdController;
use App\Http\Controllers\Api\MealPollController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\NotificationResolutionController;
use App\Http\Controllers\Api\RecipeController;
use App\Http\Controllers\Api\ShoppingListController;
use App\Http\Controllers\Api\TaskController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');

Route::prefix('auth')->group(function (): void {
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])
        ->middleware('throttle:6,1');

    Route::post('/reset-password', [AuthController::class, 'resetPassword'])
        ->middleware('throttle:6,1');
});

Broadcast::routes([
    'middleware' => ['auth:sanctum', 'throttle:broadcast', 'must.change.password'],
]);

Route::middleware(['auth:sanctum', 'throttle:api', 'must.change.password'])->group(function (): void {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // Auth / compte utilisateur
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::prefix('auth')->group(function (): void {
        Route::post('/change-initial-credentials', [AuthController::class, 'changeInitialCredentials']);
        Route::patch('/profile', [AuthController::class, 'updateProfile']);
        Route::delete('/account', [AuthController::class, 'destroyAccount']);
        Route::patch('/households/{household}/nickname', [AuthController::class, 'updateHouseholdNickname']);
    });

    // Notifications : user-scoped
    Route::prefix('notifications')->group(function (): void {
        Route::get('/pending', [NotificationController::class, 'index']);
        Route::post('/read-all', [NotificationController::class, 'markAllAsRead']);
        Route::post('/{notification}/read', [NotificationController::class, 'markAsRead']);
        Route::delete('/{notification}', [NotificationController::class, 'destroy']);

        Route::post('/{notification}/household-invite-response', [NotificationResolutionController::class, 'respondHouseholdInvite']);
        Route::post('/{notification}/household-link-response', [NotificationResolutionController::class, 'respondHouseholdLinkRequest']);
        Route::post('/{notification}/task-reassignment-response', [NotificationResolutionController::class, 'respondTaskReassignmentInvite']);
        Route::post('/{notification}/household-deletion-response', [NotificationResolutionController::class, 'respondHouseholdDeletion']);
    });

    // Création d’un foyer : user-scoped
    Route::post('/households', [HouseholdController::class, 'store']);

    // Tout ce qui dépend d’un foyer courant
    Route::middleware('household.context')->group(function (): void {
        Route::get('/dashboard', [HouseholdController::class, 'dashboard']);

        // Households
        Route::prefix('households')->group(function (): void {
            Route::get('/config', [HouseholdController::class, 'config']);
            Route::patch('/config', [HouseholdController::class, 'updateConfig']);

            Route::get('/dietary-tags', [HouseholdController::class, 'dietaryTags']);
            Route::post('/dietary-tags', [HouseholdController::class, 'createDietaryTag']);

            Route::post('/delete-request', [HouseholdController::class, 'requestDeletion']);
            Route::post('/leave', [HouseholdController::class, 'leave']);

            Route::prefix('connected-household')->group(function (): void {
                Route::get('/', [HouseholdConnectionController::class, 'show']);
                Route::post('/link-code', [HouseholdConnectionController::class, 'generateCode']);
                Route::post('/connect', [HouseholdConnectionController::class, 'submitRequest'])
                    ->middleware(['auth:sanctum', 'throttle:submit-link-code']);
                Route::post('/unlink', [HouseholdConnectionController::class, 'unlink']);
            });
        });

        Route::prefix('household')->group(function (): void {
            Route::get('/members', [HouseholdController::class, 'members']);
            Route::post('/members', [HouseholdController::class, 'addMember']);
            Route::patch('/members/{member}', [HouseholdController::class, 'updateMember']);
            Route::delete('/members/{member}', [HouseholdController::class, 'deleteMember']);
            Route::post('/members/{member}/temporary-access', [HouseholdController::class, 'refreshMemberTemporaryAccess']);
        });

        // Recipes
        Route::middleware('throttle:ai')->group(function (): void {
            Route::post('/recipes/suggest', [RecipeController::class, 'suggestIdeas']);
            Route::post('/recipes/preview-ai', [RecipeController::class, 'previewAiRecipe']);
            Route::post('/recipes/ai-store', [RecipeController::class, 'finalizeAiStore']);
        });

        Route::apiResource('recipes', RecipeController::class);
        Route::post('/recipes/{recipe}/save', [RecipeController::class, 'saveToMine']);
        Route::delete('/recipes/{recipe}/save', [RecipeController::class, 'removeFromMine']);

        // Meal polls
        Route::prefix('meal-polls')->group(function (): void {
            Route::get('/active', [MealPollController::class, 'active']);
            Route::get('/history', [MealPollController::class, 'history']);
            Route::post('/', [MealPollController::class, 'store']);
            Route::patch('/{poll}', [MealPollController::class, 'update']);
            Route::post('/{poll}/vote', [MealPollController::class, 'vote']);
            Route::post('/{poll}/votes/sync', [MealPollController::class, 'syncVotes']);
            Route::post('/{poll}/close', [MealPollController::class, 'close']);
            Route::post('/{poll}/validate', [MealPollController::class, 'validateResults']);
        });

        // Tasks
        Route::prefix('tasks')->group(function (): void {
            Route::get('/board', [TaskController::class, 'board']);

            Route::post('/templates', [TaskController::class, 'storeTemplate']);
            Route::patch('/templates/{template}', [TaskController::class, 'updateTemplate']);
            Route::delete('/templates/{template}', [TaskController::class, 'destroyTemplate']);

            Route::post('/instances', [TaskController::class, 'storeInstance']);
            Route::patch('/instances/{instance}', [TaskController::class, 'updateInstance']);
            Route::post('/instances/{instance}/validate', [TaskController::class, 'validateInstance']);
            Route::post('/instances/{instance}/reassignment-request', [TaskController::class, 'requestInstanceReassignment']);
        });

        // Calendar
        Route::prefix('calendar')->group(function (): void {
            Route::get('/board', [CalendarController::class, 'board']);

            Route::post('/events', [CalendarController::class, 'storeEvent']);
            Route::patch('/events/{event}', [CalendarController::class, 'updateEvent']);
            Route::delete('/events/{event}', [CalendarController::class, 'destroyEvent']);
            Route::post('/events/{event}/participation', [CalendarController::class, 'confirmEventParticipation']);

            Route::post('/meal-plan', [CalendarController::class, 'storeMealPlan']);
            Route::patch('/meal-plan/{mealPlan}', [CalendarController::class, 'updateMealPlan']);
            Route::delete('/meal-plan/{mealPlan}', [CalendarController::class, 'destroyMealPlan']);
            Route::post('/meal-plan/{mealPlan}/attendance', [CalendarController::class, 'confirmMealPlanAttendance']);
        });

        // Shopping lists
        Route::prefix('shopping-lists')->group(function (): void {
            Route::get('/', [ShoppingListController::class, 'index']);
            Route::post('/', [ShoppingListController::class, 'storeList']);
            Route::get('/{list}', [ShoppingListController::class, 'showList']);
            Route::delete('/{list}', [ShoppingListController::class, 'destroyList']);

            Route::post('/{list}/items', [ShoppingListController::class, 'addItem']);
            Route::patch('/items/{item}', [ShoppingListController::class, 'updateItem']);
            Route::patch('/items/{item}/toggle', [ShoppingListController::class, 'toggleItem']);
            Route::delete('/{list}/items/checked', [ShoppingListController::class, 'clearCheckedItems']);
            Route::delete('/items/{item}', [ShoppingListController::class, 'removeItem']);
        });

        // Budget
        Route::prefix('budget')->group(function (): void {
            Route::get('/history', [BudgetController::class, 'history']);
            Route::get('/board', [BudgetController::class, 'board']);

            Route::patch('/settings/{user}', [BudgetController::class, 'updateSetting']);

            Route::post('/adjustments', [BudgetController::class, 'createAdjustment']);
            Route::patch('/adjustments/{transaction}', [BudgetController::class, 'updateAdjustment']);
            Route::delete('/adjustments/{transaction}', [BudgetController::class, 'deleteAdjustment']);

            Route::post('/payments', [BudgetController::class, 'validatePayment']);
            Route::post('/advances', [BudgetController::class, 'requestAdvance']);
            Route::post('/reimbursements', [BudgetController::class, 'requestReimbursement']);
            Route::post('/advances/{transaction}/review', [BudgetController::class, 'reviewAdvance']);
        });
    });
});