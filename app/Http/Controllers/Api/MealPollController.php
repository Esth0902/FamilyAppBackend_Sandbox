<?php

namespace App\Http\Controllers\Api;

use App\Actions\MealPoll\CloseMealPollAction;
use App\Actions\MealPoll\CreateMealPollAction;
use App\Actions\MealPoll\GetActiveMealPollAction;
use App\Actions\MealPoll\GetMealPollHistoryAction;
use App\Actions\MealPoll\SyncMealPollVotesAction;
use App\Actions\MealPoll\UpdateMealPollAction;
use App\Actions\MealPoll\ValidateMealPollResultsAction;
use App\Actions\MealPoll\VoteMealPollAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\MealPoll\ActiveMealPollRequest;
use App\Http\Requests\MealPoll\CloseMealPollRequest;
use App\Http\Requests\MealPoll\HistoryMealPollRequest;
use App\Http\Requests\MealPoll\StoreMealPollRequest;
use App\Http\Requests\MealPoll\SyncMealPollVotesRequest;
use App\Http\Requests\MealPoll\UpdateMealPollRequest;
use App\Http\Requests\MealPoll\ValidateMealPollResultsRequest;
use App\Http\Requests\MealPoll\VoteMealPollRequest;
use App\Http\Resources\MealPoll\MealPollResource;
use App\Models\MealPoll;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MealPollController extends Controller
{
    public function active(
        ActiveMealPollRequest $request,
        GetActiveMealPollAction $getActiveMealPollAction,
    ): JsonResponse {
        $poll = $getActiveMealPollAction->execute($request->household());
        return response()->json(['poll' => $poll instanceof MealPoll ? MealPollResource::make($poll)->resolve($request) : null]);
    }

    public function history(
        HistoryMealPollRequest $request,
        GetMealPollHistoryAction $getMealPollHistoryAction,
    ): AnonymousResourceCollection {
        $polls = $getMealPollHistoryAction->execute($request->household(), (int) $request->input('limit', 20));
        return MealPollResource::collection($polls);
    }

    public function store(
        StoreMealPollRequest $request,
        CreateMealPollAction $createMealPollAction,
    ): JsonResponse {
        $poll = $createMealPollAction->execute($request->household(), $request->user(), $request->validated());
        return response()->json(['message' => 'Sondage ouvert avec succès.', 'poll' => MealPollResource::make($poll)->resolve($request)], 201);
    }

    public function update(
        UpdateMealPollRequest $request,
        MealPoll $poll,
        UpdateMealPollAction $updateMealPollAction,
    ): JsonResponse {
        $updatedPoll = $updateMealPollAction->execute($poll, $request->user(), $request->validated());
        return response()->json(['message' => 'Sondage mis a jour.', 'poll' => MealPollResource::make($updatedPoll)->resolve($request)]);
    }

    public function vote(
        VoteMealPollRequest $request,
        MealPoll $poll,
        VoteMealPollAction $voteMealPollAction,
    ): JsonResponse {
        $result = $voteMealPollAction->execute($poll, $request->user(), $request->selectedOption());
        return response()->json(['message' => $result['message'], 'voted' => $result['voted'], 'poll' => MealPollResource::make($result['poll'])->resolve($request)], $result['status']);
    }

    public function syncVotes(
        SyncMealPollVotesRequest $request,
        MealPoll $poll,
        SyncMealPollVotesAction $syncMealPollVotesAction,
    ): JsonResponse {
        $updatedPoll = $syncMealPollVotesAction->execute($poll, $request->user(), $request->optionIds());
        return response()->json(['message' => 'Votes enregistrés.', 'poll' => MealPollResource::make($updatedPoll)->resolve($request)]);
    }

    public function close(
        CloseMealPollRequest $request,
        MealPoll $poll,
        CloseMealPollAction $closeMealPollAction,
    ): JsonResponse {
        $result = $closeMealPollAction->execute($poll, $request->user());
        return response()->json(['message' => 'Sondage clôturé.', 'poll' => MealPollResource::make($result['poll'])->resolve($request), 'winner_recipe_id' => $result['winner_recipe_id']]);
    }

    public function validateResults(
        ValidateMealPollResultsRequest $request,
        MealPoll $poll,
        ValidateMealPollResultsAction $validateMealPollResultsAction,
    ): JsonResponse {
        $result = $validateMealPollResultsAction->execute($poll, $request->user(), $request->validated());
        return response()->json(['message' => 'Sondage validé.', 'selected_recipe_ids' => $result['selected_recipe_ids'], 'vote_stats' => $result['vote_stats'], 'poll' => MealPollResource::make($result['poll'])->resolve($request)]);
    }
}
