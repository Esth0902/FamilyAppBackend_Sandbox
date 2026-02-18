<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Household;
use App\Models\MealPlan;
use App\Models\MealPoll;
use App\Models\MealPollOption;
use App\Models\MealPollVote;
use App\Models\MealSetting;
use App\Models\Recipe;
use App\Models\User;
use App\Services\PollNotificationService;
use App\Services\RealtimePublisher;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MealPollController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly PollNotificationService $notificationService,
        private readonly RealtimePublisher $realtimePublisher,
    )
    {
    }

    public function active(Request $request): JsonResponse
    {
        $household = $this->resolveCurrentHousehold($request->user());
        if (!$household) {
            return response()->json(['message' => 'Aucun foyer associe.'], 404);
        }

        $poll = MealPoll::query()
            ->where('household_id', $household->id)
            ->whereIn('status', ['open', 'closed'])
            ->orderByDesc('starts_at')
            ->with(['options.recipe', 'votes'])
            ->first();

        if (!$poll) {
            return response()->json(['poll' => null]);
        }

        $this->authorize('view', $poll);

        if ($poll->status === 'open' && now()->greaterThan($poll->ends_at)) {
            $poll->update([
                'status' => 'closed',
                'closed_at' => $poll->closed_at ?? now(),
            ]);
            $poll->refresh();

            $this->emitPollRealtime(
                poll: $poll,
                type: 'poll.closed',
                actorUserId: (int)$request->user()->id,
            );
        }

        return response()->json([
            'poll' => $this->toPollPayload($poll, $request->user()),
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $household = $this->resolveCurrentHousehold($request->user());
        if (!$household) {
            return response()->json(['polls' => []]);
        }

        $polls = MealPoll::query()
            ->where('household_id', $household->id)
            ->where('status', 'validated')
            ->with(['options.recipe', 'votes'])
            ->orderByDesc('validated_at')
            ->limit(20)
            ->get();

        return response()->json([
            'polls' => $polls->map(fn(MealPoll $poll): array => $this->toPollPayload($poll, $request->user()))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $household = $this->resolveCurrentHousehold($user);
        if (!$household) {
            return response()->json(['message' => 'Aucun foyer associe.'], 404);
        }

        $this->authorize('create', [MealPoll::class, $household->id]);

        $validated = $request->validate([
            'title' => 'nullable|string|max:150',
            'recipe_ids' => 'required|array|min:2|max:20',
            'recipe_ids.*' => 'required|integer|exists:recipes,id',
            'duration_hours' => 'nullable|integer|min:1|max:168',
            'max_votes_per_user' => 'nullable|integer|min:1|max:20',
            'planning_start_date' => 'nullable|date_format:Y-m-d|required_with:planning_end_date',
            'planning_end_date' => 'nullable|date_format:Y-m-d|required_with:planning_start_date|after_or_equal:planning_start_date',
        ]);

        $mealSettings = MealSetting::query()->where('household_id', $household->id)->first();
        if ($mealSettings && !$mealSettings->enable_polls) {
            return response()->json(['message' => 'Le module Sondages est desactive pour ce foyer.'], 403);
        }

        $hasOpenPoll = MealPoll::query()
            ->where('household_id', $household->id)
            ->where('status', 'open')
            ->where('ends_at', '>', now())
            ->exists();

        if ($hasOpenPoll) {
            return response()->json(['message' => 'Un sondage est deja ouvert pour ce foyer.'], 422);
        }

        $recipeIds = collect($validated['recipe_ids'])->map(fn($id) => (int)$id)->unique()->values();
        $ownedRecipeCount = Recipe::query()
            ->where('household_id', $household->id)
            ->whereIn('id', $recipeIds)
            ->count();

        if ($ownedRecipeCount !== $recipeIds->count()) {
            return response()->json(['message' => 'Certaines recettes ne font pas partie de votre foyer.'], 422);
        }

        $durationHours = (int)($validated['duration_hours'] ?? ($mealSettings?->poll_duration ?? 24));
        $maxVotesPerUser = (int)($validated['max_votes_per_user'] ?? ($mealSettings?->max_votes_per_user ?? 3));
        $maxVotesPerUser = max(1, min($maxVotesPerUser, 20));
        $planningStartDate = $validated['planning_start_date'] ?? now()->toDateString();
        $planningEndDate = $validated['planning_end_date'] ?? now()->addDays(6)->toDateString();

        if ($maxVotesPerUser > $recipeIds->count()) {
            return response()->json([
                'message' => 'Le max de votes ne peut pas depasser le nombre de plats selectionnes.',
            ], 422);
        }

        $poll = DB::transaction(function () use ($household, $user, $validated, $recipeIds, $durationHours, $maxVotesPerUser, $planningStartDate, $planningEndDate): MealPoll {
            $poll = MealPoll::create([
                'household_id' => $household->id,
                'title' => trim((string)($validated['title'] ?? '')) ?: null,
                'created_by_user_id' => $user->id,
                'starts_at' => now(),
                'ends_at' => now()->addHours($durationHours),
                'planning_start_date' => $planningStartDate,
                'planning_end_date' => $planningEndDate,
                'status' => 'open',
                'max_votes_per_user' => $maxVotesPerUser,
            ]);

            $poll->options()->createMany(
                $recipeIds
                    ->map(fn(int $recipeId): array => ['recipe_id' => $recipeId])
                    ->all()
            );

            return $poll->load(['options.recipe', 'votes']);
        });

        $poll->load('household.users');
        $this->notificationService->notifyPollOpened($poll);
        $this->emitPollRealtime(
            poll: $poll,
            type: 'poll.opened',
            actorUserId: (int)$user->id,
        );

        return response()->json([
            'message' => 'Sondage ouvert avec succes.',
            'poll' => $this->toPollPayload($poll, $user),
        ], 201);
    }

    public function vote(Request $request, MealPoll $poll): JsonResponse
    {
        $this->authorize('vote', $poll);

        if ($poll->status !== 'open' || now()->greaterThan($poll->ends_at)) {
            return response()->json(['message' => 'Le sondage est cloture.'], 403);
        }

        $validated = $request->validate([
            'option_id' => 'nullable|integer',
            'recipe_id' => 'nullable|integer',
        ]);

        $option = $this->resolveOptionForVote($poll, $validated);
        if (!$option) {
            return response()->json(['message' => 'Option de vote invalide.'], 422);
        }

        $userId = (int)$request->user()->id;

        $existingVote = MealPollVote::query()
            ->where('meal_poll_id', $poll->id)
            ->where('user_id', $userId)
            ->where('meal_poll_option_id', $option->id)
            ->first();

        if ($existingVote) {
            $existingVote->delete();
            $message = 'Vote retire.';
            $voted = false;
        } else {
            $voteCount = MealPollVote::query()
                ->where('meal_poll_id', $poll->id)
                ->where('user_id', $userId)
                ->count();

            $maxVotes = max(1, (int)$poll->max_votes_per_user);
            if ($voteCount >= $maxVotes) {
                return response()->json([
                    'message' => 'Vous avez atteint le nombre maximum de votes pour ce sondage.',
                ], 422);
            }

            MealPollVote::create([
                'meal_poll_id' => $poll->id,
                'user_id' => $userId,
                'meal_poll_option_id' => $option->id,
            ]);

            $message = 'Vote ajoute.';
            $voted = true;
        }

        $poll->load(['options.recipe', 'votes']);
        $this->emitPollRealtime(
            poll: $poll,
            type: 'votes.updated',
            actorUserId: (int)$request->user()->id,
        );

        return response()->json([
            'message' => $message,
            'voted' => $voted,
            'poll' => $this->toPollPayload($poll, $request->user()),
        ]);
    }

    public function syncVotes(Request $request, MealPoll $poll): JsonResponse
    {
        $this->authorize('vote', $poll);

        if ($poll->status !== 'open' || now()->greaterThan($poll->ends_at)) {
            return response()->json(['message' => 'Le sondage est cloture.'], 403);
        }

        $validated = $request->validate([
            'option_ids' => 'required|array|min:1|max:20',
            'option_ids.*' => 'required|integer',
        ]);

        $optionIds = collect($validated['option_ids'])
            ->map(fn($id) => (int)$id)
            ->unique()
            ->values();

        $validOptionsCount = MealPollOption::query()
            ->where('meal_poll_id', $poll->id)
            ->whereIn('id', $optionIds)
            ->count();

        if ($validOptionsCount !== $optionIds->count()) {
            return response()->json(['message' => 'Une ou plusieurs options de vote sont invalides.'], 422);
        }

        $maxVotes = max(1, (int)$poll->max_votes_per_user);
        if ($optionIds->count() !== $maxVotes) {
            return response()->json([
                'message' => "Vous devez choisir exactement {$maxVotes} plats pour ce sondage.",
            ], 422);
        }

        DB::transaction(function () use ($poll, $request, $optionIds): void {
            MealPollVote::query()
                ->where('meal_poll_id', $poll->id)
                ->where('user_id', $request->user()->id)
                ->delete();

            foreach ($optionIds as $optionId) {
                MealPollVote::query()->create([
                    'meal_poll_id' => $poll->id,
                    'user_id' => (int)$request->user()->id,
                    'meal_poll_option_id' => (int)$optionId,
                ]);
            }
        });

        $poll->load(['options.recipe', 'votes']);
        $this->emitPollRealtime(
            poll: $poll,
            type: 'votes.updated',
            actorUserId: (int)$request->user()->id,
        );

        return response()->json([
            'message' => 'Votes enregistres.',
            'poll' => $this->toPollPayload($poll, $request->user()),
        ]);
    }

    public function close(Request $request, MealPoll $poll): JsonResponse
    {
        $this->authorize('close', $poll);

        if ($poll->status === 'validated') {
            return response()->json(['message' => 'Ce sondage est deja valide.'], 422);
        }

        if ($poll->status !== 'closed') {
            $poll->update([
                'status' => 'closed',
                'closed_at' => now(),
            ]);
            $poll->load('household.users');
            $this->notificationService->notifyPollNeedsValidation($poll);
            $poll->update(['close_request_sent_at' => now()]);
        }

        $poll->load(['options.recipe', 'votes']);
        $this->emitPollRealtime(
            poll: $poll,
            type: 'poll.closed',
            actorUserId: (int)$request->user()->id,
        );

        return response()->json([
            'message' => 'Sondage cloture.',
            'poll' => $this->toPollPayload($poll, $request->user()),
        ]);
    }

    public function validateResults(Request $request, MealPoll $poll): JsonResponse
    {
        $this->authorize('validate', $poll);

        $validated = $request->validate([
            'selected_recipe_ids' => 'nullable|array|min:1',
            'selected_recipe_ids.*' => 'required|integer|exists:recipes,id',
            'meal_plan' => 'nullable|array|min:1',
            'meal_plan.*.date' => 'required|date',
            'meal_plan.*.meal_type' => 'required|string|in:matin,midi,soir',
            'meal_plan.*.recipe_id' => 'required|integer|exists:recipes,id',
            'meal_plan.*.servings' => 'nullable|integer|min:1|max:30',
            'meal_plan.*.note' => 'nullable|string|max:255',
        ]);

        $voteStats = $this->collectVoteStats($poll);

        $defaultSelectedRecipeIds = collect($voteStats)
            ->where('votes_count', '>', 0)
            ->sortByDesc('votes_count')
            ->pluck('recipe_id')
            ->values();

        if ($defaultSelectedRecipeIds->isEmpty()) {
            $defaultSelectedRecipeIds = $poll->options()->pluck('recipe_id')->take(3)->values();
        }

        $selectedRecipeIds = collect($validated['selected_recipe_ids'] ?? $defaultSelectedRecipeIds)
            ->map(fn($id) => (int)$id)
            ->unique()
            ->values();

        if ($selectedRecipeIds->isEmpty()) {
            return response()->json(['message' => 'Aucune recette selectionnee pour validation.'], 422);
        }

        $allowedRecipeIds = Recipe::query()
            ->where('household_id', $poll->household_id)
            ->whereIn('id', $selectedRecipeIds)
            ->pluck('id');

        if ($allowedRecipeIds->count() !== $selectedRecipeIds->count()) {
            return response()->json(['message' => 'Certaines recettes selectionnees ne sont pas dans le foyer.'], 422);
        }

        $planningStartDate = optional($poll->planning_start_date)->toDateString();
        $planningEndDate = optional($poll->planning_end_date)->toDateString();
        if ($planningStartDate && $planningEndDate) {
            foreach ($validated['meal_plan'] ?? [] as $entry) {
                $entryDate = Carbon::parse((string)$entry['date'])->toDateString();
                if ($entryDate < $planningStartDate || $entryDate > $planningEndDate) {
                    return response()->json([
                        'message' => 'La date de planification doit etre comprise dans la plage du sondage.',
                    ], 422);
                }
            }
        }

        DB::transaction(function () use ($poll, $request, $validated): void {
            if ($poll->status !== 'validated') {
                $poll->update([
                    'status' => 'validated',
                    'closed_at' => $poll->closed_at ?? now(),
                    'validated_at' => now(),
                    'validated_by_user_id' => $request->user()->id,
                ]);
            }

            $mealPlanEntries = $validated['meal_plan'] ?? [];
            foreach ($mealPlanEntries as $entry) {
                $mealPlanUpdatePayload = [
                    'note' => $entry['note'] ?? null,
                ];

                // Compatibilite avec le schema legacy (meal_plans avec recipe_id + servings en NOT NULL).
                if (Schema::hasColumn('meal_plans', 'recipe_id')) {
                    $mealPlanUpdatePayload['recipe_id'] = (int)$entry['recipe_id'];
                }
                if (Schema::hasColumn('meal_plans', 'servings')) {
                    $mealPlanUpdatePayload['servings'] = (int)($entry['servings'] ?? 4);
                }

                $mealPlan = MealPlan::query()->updateOrCreate(
                    [
                        'household_id' => $poll->household_id,
                        'date' => $entry['date'],
                        'meal_type' => $entry['meal_type'],
                    ],
                    $mealPlanUpdatePayload
                );

                $mealPlan->items()->delete();
                $mealPlan->items()->create([
                    'recipe_id' => (int)$entry['recipe_id'],
                    'servings' => (int)($entry['servings'] ?? 4),
                    'position' => 1,
                ]);
            }
        });

        $poll->refresh()->load(['options.recipe', 'votes']);
        $poll->load('household.users');
        $this->notificationService->notifyPollValidated($poll);
        $this->emitPollRealtime(
            poll: $poll,
            type: 'poll.validated',
            actorUserId: (int)$request->user()->id,
        );

        return response()->json([
            'message' => 'Sondage valide.',
            'selected_recipe_ids' => $selectedRecipeIds,
            'vote_stats' => $voteStats,
            'poll' => $this->toPollPayload($poll, $request->user()),
        ]);
    }

    private function resolveCurrentHousehold(User $user): ?Household
    {
        return $user->households()->first();
    }

    private function resolveOptionForVote(MealPoll $poll, array $validated): ?MealPollOption
    {
        $optionId = isset($validated['option_id']) ? (int)$validated['option_id'] : null;
        $recipeId = isset($validated['recipe_id']) ? (int)$validated['recipe_id'] : null;

        if ($optionId) {
            return MealPollOption::query()
                ->where('meal_poll_id', $poll->id)
                ->where('id', $optionId)
                ->first();
        }

        if ($recipeId) {
            return MealPollOption::query()
                ->where('meal_poll_id', $poll->id)
                ->where('recipe_id', $recipeId)
                ->first();
        }

        return null;
    }

    private function toPollPayload(MealPoll $poll, User $user): array
    {
        $poll->loadMissing(['options.recipe', 'votes']);

        $voteCounts = MealPollVote::query()
            ->where('meal_poll_id', $poll->id)
            ->select('meal_poll_option_id', DB::raw('COUNT(*) as votes_count'))
            ->groupBy('meal_poll_option_id')
            ->pluck('votes_count', 'meal_poll_option_id');

        $userVotedOptionIds = MealPollVote::query()
            ->where('meal_poll_id', $poll->id)
            ->where('user_id', $user->id)
            ->pluck('meal_poll_option_id')
            ->map(fn($id) => (int)$id)
            ->values();

        $votesByUser = MealPollVote::query()
            ->where('meal_poll_id', $poll->id)
            ->select('user_id', DB::raw('COUNT(*) as votes_count'))
            ->groupBy('user_id')
            ->orderByDesc('votes_count')
            ->get();

        $votesByOption = MealPollVote::query()
            ->join('users', 'users.id', '=', 'meal_poll_votes.user_id')
            ->where('meal_poll_votes.meal_poll_id', $poll->id)
            ->select('meal_poll_votes.meal_poll_option_id', 'users.id as user_id', 'users.name')
            ->orderBy('users.name')
            ->get()
            ->groupBy('meal_poll_option_id');

        $voterUsers = User::query()
            ->whereIn('id', $votesByUser->pluck('user_id')->map(fn($id) => (int)$id))
            ->get()
            ->keyBy('id');

        $votersSummary = $votesByUser
            ->map(static function ($row) use ($voterUsers): array {
                $userId = (int)$row->user_id;
                $voter = $voterUsers->get($userId);

                return [
                    'user_id' => $userId,
                    'name' => $voter?->name ?? 'Utilisateur',
                    'votes_count' => (int)$row->votes_count,
                ];
            })
            ->values();

        $options = $poll->options
            ->sortBy('id')
            ->map(function (MealPollOption $option) use ($voteCounts, $userVotedOptionIds, $votesByOption): array {
                $votesCount = (int)($voteCounts[$option->id] ?? 0);
                $optionVoters = collect($votesByOption->get($option->id, []))
                    ->map(static fn($row): array => [
                        'user_id' => (int)$row->user_id,
                        'name' => (string)$row->name,
                    ])
                    ->values();
                return [
                    'id' => $option->id,
                    'recipe_id' => $option->recipe_id,
                    'votes_count' => $votesCount,
                    'is_voted_by_me' => $userVotedOptionIds->contains((int)$option->id),
                    'voters' => $optionVoters,
                    'recipe' => [
                        'id' => $option->recipe?->id,
                        'title' => $option->recipe?->title,
                        'type' => $option->recipe?->type,
                        'description' => $option->recipe?->description,
                    ],
                ];
            })
            ->values();

        return [
            'id' => $poll->id,
            'household_id' => $poll->household_id,
            'title' => $poll->title,
            'status' => $poll->status,
            'starts_at' => optional($poll->starts_at)->toIso8601String(),
            'ends_at' => optional($poll->ends_at)->toIso8601String(),
            'planning_start_date' => optional($poll->planning_start_date)->toDateString(),
            'planning_end_date' => optional($poll->planning_end_date)->toDateString(),
            'closed_at' => optional($poll->closed_at)->toIso8601String(),
            'validated_at' => optional($poll->validated_at)->toIso8601String(),
            'max_votes_per_user' => (int)$poll->max_votes_per_user,
            'my_votes_count' => $userVotedOptionIds->count(),
            'my_voted_option_ids' => $userVotedOptionIds,
            'voters_summary' => $votersSummary,
            'options' => $options,
        ];
    }

    private function collectVoteStats(MealPoll $poll): array
    {
        $stats = MealPollVote::query()
            ->select('meal_poll_options.recipe_id', DB::raw('COUNT(*) as votes_count'))
            ->join('meal_poll_options', 'meal_poll_options.id', '=', 'meal_poll_votes.meal_poll_option_id')
            ->where('meal_poll_votes.meal_poll_id', $poll->id)
            ->groupBy('meal_poll_options.recipe_id')
            ->orderByDesc('votes_count')
            ->get();

        return $stats
            ->map(static fn($row): array => [
                'recipe_id' => (int)$row->recipe_id,
                'votes_count' => (int)$row->votes_count,
            ])
            ->values()
            ->all();
    }

    private function emitPollRealtime(MealPoll $poll, string $type, int $actorUserId): void
    {
        $this->realtimePublisher->publishHousehold(
            householdId: (int)$poll->household_id,
            module: 'meal_poll',
            type: $type,
            payload: [
                'poll_id' => (int)$poll->id,
                'status' => (string)$poll->status,
                'actor_user_id' => $actorUserId,
            ],
        );
    }
}

