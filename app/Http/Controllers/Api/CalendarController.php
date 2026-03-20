<?php

namespace App\Http\Controllers\Api;

use App\Actions\Calendar\ConfirmEventParticipationAction;
use App\Actions\Calendar\ConfirmMealPlanAttendanceAction;
use App\Actions\Calendar\DestroyEventAction;
use App\Actions\Calendar\DestroyMealPlanAction;
use App\Actions\Calendar\StoreEventAction;
use App\Actions\Calendar\StoreMealPlanAction;
use App\Actions\Calendar\UpdateEventAction;
use App\Actions\Calendar\UpdateMealPlanAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Calendar\CalendarBoardRequest;
use App\Http\Requests\Calendar\ConfirmEventParticipationRequest;
use App\Http\Requests\Calendar\ConfirmMealPlanAttendanceRequest;
use App\Http\Requests\Calendar\DestroyEventRequest;
use App\Http\Requests\Calendar\DestroyMealPlanRequest;
use App\Http\Requests\Calendar\StoreEventRequest;
use App\Http\Requests\Calendar\StoreMealPlanRequest;
use App\Http\Requests\Calendar\UpdateEventRequest;
use App\Http\Resources\Calendar\CalendarBoardResource;
use App\Http\Resources\Calendar\EventParticipationResource;
use App\Http\Resources\Calendar\EventResource;
use App\Http\Resources\Calendar\MealPlanAttendanceResource;
use App\Http\Resources\Calendar\MealPlanResource;
use App\Models\Event;
use App\Models\MealPlan;
use App\Queries\Calendar\GetCalendarBoardQuery;
use Illuminate\Http\JsonResponse;

class CalendarController extends Controller
{
    public function board(CalendarBoardRequest $request, GetCalendarBoardQuery $query): JsonResponse
    {
        return response()->json(CalendarBoardResource::make($query->execute($request))->resolve($request));
    }

    public function storeEvent(StoreEventRequest $request, StoreEventAction $action): JsonResponse
    {
        $event = $action->execute($request->household(), $request->user(), $request->validated());
        return EventResource::mutation($event, (int) $request->user()->id, $request->householdRole(), (int) $request->household()->id, [], 'Evenement cree.')->response()->setStatusCode(201);
    }

    public function updateEvent(UpdateEventRequest $request, Event $event, UpdateEventAction $action): JsonResponse
    {
        $event = $action->execute($request->household(), $request->user(), $event, $request->validated());
        return EventResource::mutation($event, (int) $request->user()->id, $request->householdRole(), (int) $request->household()->id, [], 'Evenement mis a jour.')->response();
    }

    public function destroyEvent(DestroyEventRequest $request, Event $event, DestroyEventAction $action): JsonResponse
    {
        $action->execute($request->household(), $request->user(), $event);
        return EventResource::deleted('Evenement supprime.')->response();
    }

    public function storeMealPlan(StoreMealPlanRequest $request, StoreMealPlanAction $action): JsonResponse
    {
        $mealPlan = $action->execute($request->household(), $request->user(), $request->validated(), $request->mealPlanUpdatePayload(), $request->recipeId(), $request->servings());
        return MealPlanResource::mutation($mealPlan, (int) $request->user()->id, [], $mealPlan->wasRecentlyCreated ? 'Meal plan cree.' : 'Meal plan mis a jour.')->response()->setStatusCode($mealPlan->wasRecentlyCreated ? 201 : 200);
    }

    public function updateMealPlan(StoreMealPlanRequest $request, MealPlan $mealPlan, UpdateMealPlanAction $action): JsonResponse
    {
        $mealPlan = $action->execute($request->household(), $request->user(), $mealPlan, $request->mealPlanUpdatePayload(), $request->recipeId(), $request->servings());
        return MealPlanResource::mutation($mealPlan, (int) $request->user()->id, [], 'Meal plan mis a jour.')->response();
    }

    public function destroyMealPlan(DestroyMealPlanRequest $request, MealPlan $mealPlan, DestroyMealPlanAction $action): JsonResponse
    {
        $action->execute($request->household(), $request->user(), $mealPlan);
        return MealPlanResource::deleted('Meal plan supprime.')->response();
    }

    public function confirmMealPlanAttendance(ConfirmMealPlanAttendanceRequest $request, MealPlan $mealPlan, ConfirmMealPlanAttendanceAction $action): JsonResponse
    {
        $attendance = $action->execute($request->household(), $request->user(), $mealPlan, $request->validated());
        return MealPlanAttendanceResource::mutation($attendance, 'Presence au repas enregistree.')->response()->setStatusCode(200);
    }

    public function confirmEventParticipation(ConfirmEventParticipationRequest $request, Event $event, ConfirmEventParticipationAction $action): JsonResponse
    {
        $participation = $action->execute($request->household(), $request->user(), $event, $request->validated());
        return EventParticipationResource::mutation($participation, 'Participation a l evenement enregistree.')->response()->setStatusCode(200);
    }
}
