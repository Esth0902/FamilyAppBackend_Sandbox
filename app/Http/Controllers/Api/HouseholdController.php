<?php

namespace App\Http\Controllers\Api;

use App\Actions\Household\LeaveHouseholdAction;
use App\Actions\Household\UpdateMemberAction;
use App\Http\Controllers\Api\Concerns\ResolvesHouseholdContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Household\AddHouseholdMemberRequest;
use App\Http\Requests\Household\CreateDietaryTagRequest;
use App\Http\Requests\Household\DeleteHouseholdMemberRequest;
use App\Http\Requests\Household\RefreshMemberAccessRequest;
use App\Http\Requests\Household\StoreHouseholdRequest;
use App\Http\Requests\Household\UpdateHouseholdMemberRequest;
use App\Http\Requests\Household\UpdateHouseholdConfigRequest;
use App\Models\BudgetSetting;
use App\Models\DietaryTag;
use App\Models\Household;
use App\Models\HouseholdSetting;
use App\Models\MealPoll;
use App\Models\MealPollVote;
use App\Models\TaskInstance;
use App\Models\TaskTemplate;
use App\Support\JsonUtf8Sanitizer;
use App\Services\HouseholdDeletionService;
use App\Services\HouseholdManagerService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenAI\Laravel\Facades\OpenAI;

class HouseholdController extends Controller
{
    use ResolvesHouseholdContext;

    private const DIETARY_TAG_TYPES = ['diet', 'allergen', 'dislike', 'restriction', 'cuisine_rule'];
    private const DIETARY_TAG_SIMILARITY_THRESHOLD = 0.10;
    private const TASK_STATUS_TODO = 'à faire';
    private const TASK_STATUS_DONE = 'réalisée';

    public function __construct(
        private readonly HouseholdDeletionService $householdDeletionService,
        private readonly HouseholdManagerService $householdManagerService,
        private readonly UpdateMemberAction $updateMemberAction,
        private readonly LeaveHouseholdAction $leaveHouseholdAction,
    ) {
    }

    public function store(StoreHouseholdRequest $request)
    {
        $user = $this->resolveAuthenticatedUser($request);
        $validated = $request->validated();
        $result = $this->householdManagerService->register(
            owner: $user,
            validated: $validated,
        );

        return response()->json(
            JsonUtf8Sanitizer::sanitize($result['payload']),
            (int) $result['status']
        );
    }

    public function members(Request $request)
    {
        [$household, $role] = $this->resolveHouseholdWithRole($request);

        $members = $household->users()
            ->select([
                'users.id',
                'users.name',
                'users.email',
                'users.must_change_password',
            ])
            ->orderByRaw("CASE WHEN household_user.role = ? THEN 0 ELSE 1 END", [User::ROLE_PARENT])
            ->orderBy('users.name')
            ->get()
            ->map(fn(User $member): array => $this->toHouseholdMemberPayload($member))
            ->values();

        return response()->json(JsonUtf8Sanitizer::sanitize([
            'household' => [
                'id' => (int) $household->id,
                'name' => (string) $household->name,
            ],
            'permissions' => [
                'can_manage_members' => $role === User::ROLE_PARENT,
            ],
            'members' => $members,
        ]));
    }

    public function addMember(AddHouseholdMemberRequest $request)
    {
        $user = $this->resolveAuthenticatedUser($request);
        $validated = $request->validated();
        $result = $this->householdManagerService->inviteMember(
            household: $request->household(),
            inviter: $user,
            validated: $validated,
        );

        return response()->json(
            JsonUtf8Sanitizer::sanitize($result['payload']),
            (int) $result['status']
        );
    }

    public function updateMember(UpdateHouseholdMemberRequest $request, User $member)
    {
        $result = $this->updateMemberAction->execute(
            household: $request->household(),
            member: $member,
            validated: $request->validated(),
        );

        /** @var User $updatedMember */
        $updatedMember = $result['member'];
        $response = [
            'message' => 'Membre mis a jour.',
            'member' => $this->toHouseholdMemberPayload($updatedMember),
        ];

        $generatedEmail = $result['generated_email'] ?? null;
        if (is_string($generatedEmail) && trim($generatedEmail) !== '') {
            $response['generated_email'] = $generatedEmail;
        }

        return response()->json(JsonUtf8Sanitizer::sanitize($response));
    }

    public function deleteMember(DeleteHouseholdMemberRequest $request, User $member)
    {
        $household = $request->household();

        DB::transaction(function () use ($household, $member): void {
            $household->users()->detach($member->id);

            BudgetSetting::query()
                ->where('household_id', $household->id)
                ->where('user_id', $member->id)
                ->delete();
        });

        return response()->json([
            'message' => 'Membre supprimé du foyer.',
            'deleted_member_id' => (int) $member->id,
        ]);
    }

    public function refreshMemberTemporaryAccess(RefreshMemberAccessRequest $request, User $member)
    {
        $household = $request->household();
        $rawPassword = Str::random(10);
        $member->forceFill([
            'password' => $rawPassword,
            'must_change_password' => true,
        ])->save();

        $freshMember = $household->users()
            ->where('users.id', $member->id)
            ->firstOrFail();

        return response()->json(JsonUtf8Sanitizer::sanitize([
            'message' => 'Nouvel accès temporaire généré.',
            'member' => $this->toHouseholdMemberPayload($freshMember),
            'generated_email' => (string) $freshMember->email,
            'generated_password' => $rawPassword,
            'share_text' => $this->buildMemberShareText(
                (string) $freshMember->name,
                (string) $freshMember->email,
                $rawPassword
            ),
        ]));
    }

    public function requestDeletion(Request $request)
    {
        [$household, $role] = $this->resolveHouseholdWithRole($request);
        $this->ensureParentRole($role);
        $user = $this->resolveAuthenticatedUser($request);

        $result = $this->householdDeletionService->requestDeletion($household, $user);
        $scheduledFor = data_get($result, 'scheduled_for');

        return response()->json(JsonUtf8Sanitizer::sanitize([
            'message' => $scheduledFor
                ? 'La suppression du foyer est planifiée dans 24h.'
                : 'La demande de suppression a été envoyée aux autres parents.',
            'deletion_request' => [
                'request_id' => (string) data_get($result, 'request_id', ''),
                'status' => (string) data_get($result, 'status', 'pending_approvals'),
                'scheduled_for' => is_string($scheduledFor) ? $scheduledFor : null,
                'approvals_required' => (int) data_get($result, 'approvals_required', 0),
                'approvals_received' => (int) data_get($result, 'approvals_received', 0),
            ],
        ]));
    }

    public function leave(Request $request)
    {
        [$household, $role] = $this->resolveHouseholdWithRole($request);
        $this->ensureParentRole($role);
        $member = $this->resolveAuthenticatedUser($request);

        $freshUser = $this->leaveHouseholdAction->execute(
            household: $household,
            member: $member,
        );

        return response()->json(JsonUtf8Sanitizer::sanitize([
            'message' => 'Vous avez quitté ce foyer.',
            'left_household_id' => (int) $household->id,
            'user' => $freshUser,
        ]));
    }

    public function dashboard(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié.'], 401);
        }
        $household = $this->resolveCurrentHousehold($user, $request);

        if (!$household) {
            return response()->json(['message' => 'Aucun foyer', 'requires_setup' => true]);
        }

        $household->load('users');
        $settings = HouseholdSetting::query()
            ->where('household_id', $household->id)
            ->first();

        $polls = MealPoll::query()
            ->where('household_id', $household->id)
            ->with(['options.recipe', 'votes.user'])
            ->orderByDesc('starts_at')
            ->get();

        $pollsPayload = $polls
            ->map(fn(MealPoll $poll): array => $this->toDashboardPollPayload($poll))
            ->values();

        $openPolls = $pollsPayload
            ->filter(fn(array $poll): bool => ($poll['status'] ?? null) === 'open')
            ->values();

        $closedPolls = $pollsPayload
            ->filter(fn(array $poll): bool => in_array((string)($poll['status'] ?? ''), ['closed', 'validated'], true))
            ->values();

        $favoriteRecipes = $this->buildFavoriteRecipesPayload($household->id);
        $tasksEnabled = (bool) ($settings?->has_tasks ?? false);
        $weekStart = now()->startOfWeek(Carbon::MONDAY)->toDateString();
        $weekEnd = now()->endOfWeek(Carbon::SUNDAY)->toDateString();
        $tasksSummary = [
            'enabled' => $tasksEnabled,
            'range' => [
                'from' => $weekStart,
                'to' => $weekEnd,
            ],
            'todo_count' => 0,
            'done_count' => 0,
            'validated_count' => 0,
        ];

        if ($tasksEnabled) {
            $taskInstances = TaskInstance::query()
                ->whereHas('template', fn($query) => $query->where('household_id', $household->id))
                ->whereBetween('due_date', [$weekStart, $weekEnd])
                ->get(['status', 'validated_by_parent']);

            $tasksSummary['todo_count'] = $taskInstances
                ->filter(fn(TaskInstance $instance): bool => (string) $instance->status === self::TASK_STATUS_TODO)
                ->count();
            $tasksSummary['done_count'] = $taskInstances
                ->filter(fn(TaskInstance $instance): bool => (string) $instance->status === self::TASK_STATUS_DONE)
                ->count();
            $tasksSummary['validated_count'] = $taskInstances
                ->filter(fn(TaskInstance $instance): bool => (bool) $instance->validated_by_parent)
                ->count();
        }

        return response()->json(JsonUtf8Sanitizer::sanitize([
            'household_name' => $household->name,
            'members' => $household->users,
            'active_poll' => $openPolls->first(),
            'polls_open' => $openPolls,
            'polls_closed' => $closedPolls,
            'polls' => $pollsPayload,
            'favorite_recipes' => $favoriteRecipes,
            'tasks_summary' => $tasksSummary,
        ]));
    }

    private function toDashboardPollPayload(MealPoll $poll): array
    {
        $votesByOption = $poll->votes->groupBy('meal_poll_option_id');

        $options = $poll->options
            ->sortBy('id')
            ->map(function ($option) use ($votesByOption): array {
                $optionVotes = $votesByOption->get($option->id, collect());

                return [
                    'id' => (int)$option->id,
                    'recipe_id' => (int)$option->recipe_id,
                    'title' => (string)($option->recipe?->title ?? 'Recette'),
                    'votes_count' => (int)$optionVotes->count(),
                ];
            })
            ->values();

        $votesByUser = $poll->votes
            ->groupBy('user_id')
            ->map(function (Collection $userVotes, $userId): array {
                $firstVote = $userVotes->first();
                $name = (string)($firstVote?->user?->name ?? 'Utilisateur');

                return [
                    'user_id' => (int)$userId,
                    'name' => $name,
                    'votes_count' => (int)$userVotes->count(),
                ];
            })
            ->values();

        return [
            'id' => (int)$poll->id,
            'title' => $poll->title,
            'status' => (string)$poll->status,
            'starts_at' => optional($poll->starts_at)->toIso8601String(),
            'ends_at' => optional($poll->ends_at)->toIso8601String(),
            'planning_start_date' => optional($poll->planning_start_date)->toDateString(),
            'planning_end_date' => optional($poll->planning_end_date)->toDateString(),
            'max_votes_per_user' => (int)$poll->max_votes_per_user,
            'total_votes' => (int)$poll->votes->count(),
            'options' => $options,
            'voters_summary' => $votesByUser,
        ];
    }

    private function buildFavoriteRecipesPayload(int $householdId): array
    {
        return MealPollVote::query()
            ->join('meal_poll_options', 'meal_poll_options.id', '=', 'meal_poll_votes.meal_poll_option_id')
            ->join('meal_polls', 'meal_polls.id', '=', 'meal_poll_votes.meal_poll_id')
            ->join('recipes', 'recipes.id', '=', 'meal_poll_options.recipe_id')
            ->where('meal_polls.household_id', $householdId)
            ->whereIn('meal_polls.status', ['closed', 'validated'])
            ->groupBy('meal_poll_options.recipe_id', 'recipes.title')
            ->selectRaw('meal_poll_options.recipe_id as recipe_id')
            ->selectRaw('recipes.title as title')
            ->selectRaw('COUNT(meal_poll_votes.id) as votes_count')
            ->selectRaw('COUNT(DISTINCT meal_poll_votes.meal_poll_id) as polls_count')
            ->orderByDesc('votes_count')
            ->orderBy('recipes.title')
            ->limit(10)
            ->get()
            ->map(static fn($row): array => [
                'recipe_id' => (int)$row->recipe_id,
                'title' => (string)$row->title,
                'votes_count' => (int)$row->votes_count,
                'polls_count' => (int)$row->polls_count,
            ])
            ->values()
            ->all();
    }

    public function config(Request $request)
    {
        $household = $this->resolveEditableHousehold($request);
        $household->load(['settings', 'mealSettings', 'dietaryTags']);

        $settings = $household->settings;
        $mealSettings = $household->mealSettings;
        $tasksConfig = is_array($settings?->tasks_config) ? $settings->tasks_config : [];
        $calendarConfig = is_array($settings?->calendar_config) ? $settings->calendar_config : [];
        $budgetConfig = is_array($settings?->budget_config) ? $settings->budget_config : [];
        $custodyChangeDay = $this->householdManagerService->normalizeIsoWeekDayForConfig(
            $tasksConfig['custody_change_day'] ?? 5,
            5
        );
        $custodyHomeWeekStart = $this->householdManagerService->resolveCustodyHomeWeekStartForConfig(
            (bool) ($tasksConfig['alternating_custody_enabled'] ?? false),
            $tasksConfig['custody_home_week_start'] ?? null,
            $custodyChangeDay
        );
        $taskTemplates = TaskTemplate::where('household_id', $household->id)
            ->orderBy('id')
            ->get();

        return response()->json(JsonUtf8Sanitizer::sanitize([
            'household' => [
                'id' => $household->id,
                'name' => $household->name,
            ],
            'config' => [
                'household_name' => $household->name,
                'modules' => [
                    'meals' => [
                        'enabled' => (bool)($settings?->has_meals ?? true),
                        'options' => [
                            'recipes' => (bool)($mealSettings?->enable_recipes ?? true),
                            'polls' => (bool)($mealSettings?->enable_polls ?? true),
                            'shopping_list' => (bool)($mealSettings?->enable_shopping_list ?? true),
                        ],
                        'settings' => [
                            'poll_day' => (int)($mealSettings?->poll_day ?? 5),
                            'poll_time' => (string)($mealSettings?->poll_time ?? '10:00'),
                            'poll_duration' => (int)($mealSettings?->poll_duration ?? 24),
                            'max_votes_per_user' => (int)($mealSettings?->max_votes_per_user ?? 3),
                            'default_servings' => (int)($mealSettings?->default_servings ?? 4),
                            'dietary_tags' => $household->dietaryTags
                                ->pluck('key')
                                ->filter(static fn(mixed $key): bool => is_string($key) && trim($key) !== '')
                                ->values(),
                            'dietary_tag_details' => $household->dietaryTags
                                ->map(static function (DietaryTag $tag): array {
                                    return [
                                        'key' => $tag->key,
                                        'label' => $tag->label,
                                        'type' => $tag->type,
                                    ];
                                })
                                ->values(),
                        ],
                    ],
                    'tasks' => [
                        'enabled' => (bool)($settings?->has_tasks ?? false),
                        'settings' => [
                            'reminders_enabled' => (bool)($tasksConfig['reminders_enabled'] ?? true),
                            'alternating_custody_enabled' => (bool)($tasksConfig['alternating_custody_enabled'] ?? false),
                            'custody_change_day' => $custodyChangeDay,
                            'custody_home_week_start' => $custodyHomeWeekStart,
                            'templates' => $taskTemplates->map(function (TaskTemplate $template): array {
                                return [
                                    'id' => (int)$template->id,
                                    'name' => $template->name,
                                    'description' => $template->description,
                                    'recurrence' => $template->recurrence,
                                    'recurrence_days' => $this->householdManagerService->normalizeTaskRecurrenceDaysForConfig($template->recurrence_days),
                                    'is_rotation' => (bool)$template->is_rotation,
                                    'rotation_cycle_weeks' => $this->householdManagerService->normalizeRotationCycleWeeksForConfig($template->rotation_cycle_weeks ?? 1),
                                    'is_inter_household_alternating' => (bool)($template->is_inter_household_alternating ?? false),
                                    'inter_household_week_start' => optional($template->inter_household_week_start)->toDateString(),
                                    'fixed_user_id' => $template->fixed_user_id ? (int)$template->fixed_user_id : null,
                                ];
                            })->values(),
                        ],
                    ],
                    'calendar' => [
                        'enabled' => (bool)($settings?->has_calendar ?? false),
                        'settings' => $calendarConfig,
                    ],
                    'budget' => [
                        'enabled' => (bool)($settings?->has_budget ?? false),
                        'settings' => $budgetConfig,
                    ],
                ],
            ],
        ]));
    }

    public function dietaryTags(Request $request)
    {
        $household = $this->resolveEditableHousehold($request);
        $search = trim((string)$request->query('q', ''));
        $type = trim((string)$request->query('type', ''));
        $normalizedType = in_array($type, self::DIETARY_TAG_TYPES, true) ? $type : null;

        $typeOrder = "CASE type
            WHEN 'diet' THEN 1
            WHEN 'allergen' THEN 2
            WHEN 'restriction' THEN 3
            WHEN 'dislike' THEN 4
            WHEN 'cuisine_rule' THEN 5
            ELSE 6
        END";

        $tags = DietaryTag::query()
            ->where(function ($query) use ($household): void {
                $query
                    ->where('is_system', true)
                    ->orWhere('created_by_household_id', $household->id)
                    ->orWhereHas('households', function ($householdQuery) use ($household): void {
                        $householdQuery->where('households.id', $household->id);
                    });
            })
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($filterQuery) use ($search): void {
                    $slugSearch = Str::slug($search);
                    $filterQuery
                        ->where('label', 'ILIKE', '%' . $search . '%')
                        ->orWhere('key', 'ILIKE', '%' . $slugSearch . '%');
                });
            })
            ->when($normalizedType !== null, function ($query) use ($normalizedType): void {
                $query->where('type', $normalizedType);
            })
            ->select(['id', 'type', 'key', 'label', 'is_system'])
            ->orderByRaw($typeOrder)
            ->orderBy('label')
            ->limit(60)
            ->get();

        return response()->json($tags);
    }

    public function createDietaryTag(CreateDietaryTagRequest $request)
    {
        $household = $request->household();
        $validated = $request->validated();

        $label = trim((string)$validated['label']);
        $type = (string)$validated['type'];
        $key = Str::slug($label);

        $existingTag = DietaryTag::query()
            ->where('type', $type)
            ->where('key', $key)
            ->first();

        if ($existingTag) {
            $household->dietaryTags()->syncWithoutDetaching([$existingTag->id]);
            return response()->json([
                'message' => 'Ce tag existe deja.',
                'created' => false,
                'tag' => [
                    'id' => $existingTag->id,
                    'type' => $existingTag->type,
                    'key' => $existingTag->key,
                    'label' => $existingTag->label,
                    'is_system' => (bool)$existingTag->is_system,
                ],
            ]);
        }

        $embedding = $this->generateEmbeddingVector($type . ': ' . $label);
        $closestTag = $this->findClosestDietaryTagByEmbedding($type, $embedding);
        if ($closestTag && $closestTag['distance'] <= self::DIETARY_TAG_SIMILARITY_THRESHOLD) {
            return response()->json([
                'message' => 'Un tag tres proche existe deja.',
                'created' => false,
                'closest_tag' => $closestTag,
            ], 409);
        }

        $newTag = DietaryTag::create([
            'type' => $type,
            'key' => $key,
            'label' => $label,
            'is_system' => false,
            'created_by_household_id' => $household->id,
            'embedding' => $embedding ? $this->toVectorLiteral($embedding) : null,
        ]);

        $household->dietaryTags()->syncWithoutDetaching([$newTag->id]);

        return response()->json([
            'message' => 'Tag ajoute.',
            'created' => true,
            'tag' => [
                'id' => $newTag->id,
                'type' => $newTag->type,
                'key' => $newTag->key,
                'label' => $newTag->label,
                'is_system' => (bool)$newTag->is_system,
            ],
        ], 201);
    }

    public function updateConfig(UpdateHouseholdConfigRequest $request)
    {
        [$household, $role] = $this->resolveHouseholdWithRole($request);
        $this->ensureParentRole($role);
        $user = $this->resolveAuthenticatedUser($request);

        $validated = $request->validated();
        $result = $this->householdManagerService->updateConfiguration(
            household: $household,
            user: $user,
            validated: $validated,
        );

        return response()->json(
            JsonUtf8Sanitizer::sanitize($result['payload']),
            (int) $result['status']
        );
    }

    private function generateEmbeddingVector(string $text): ?array
    {
        $input = trim($text);
        if ($input === '') {
            return null;
        }

        try {
            $response = OpenAI::embeddings()->create([
                'model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
                'input' => $input,
                'dimensions' => 512,
            ]);

            $embedding = $response->embeddings[0]->embedding ?? null;
            if (!is_array($embedding) || count($embedding) !== 512) {
                return null;
            }

            return array_map(static fn($value): float => (float)$value, $embedding);
        } catch (\Throwable $e) {
            Log::warning('Embedding generation failed for dietary tag: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * @param  array<int, float>  $embedding
     */
    private function toVectorLiteral(array $embedding): string
    {
        return '[' . implode(',', array_map(
            static fn(float $value): string => rtrim(rtrim(sprintf('%.8F', $value), '0'), '.'),
            $embedding
        )) . ']';
    }

    /**
     * @param  array<int, float>|null  $embedding
     * @return array{id:int,key:string,label:string,type:string,is_system:bool,distance:float}|null
     */
    private function findClosestDietaryTagByEmbedding(string $type, ?array $embedding): ?array
    {
        if (!$embedding || count($embedding) !== 512) {
            return null;
        }

        $vectorLiteral = $this->toVectorLiteral($embedding);

        $rows = DB::select(
            'SELECT id, key, label, type, is_system, (embedding <=> ?::vector) AS distance
             FROM dietary_tags
             WHERE type = ? AND embedding IS NOT NULL
             ORDER BY embedding <=> ?::vector
             LIMIT 1',
            [$vectorLiteral, $type, $vectorLiteral]
        );

        if (count($rows) === 0) {
            return null;
        }

        $closest = $rows[0];
        return [
            'id' => (int)$closest->id,
            'key' => (string)$closest->key,
            'label' => (string)$closest->label,
            'type' => (string)$closest->type,
            'is_system' => (bool)$closest->is_system,
            'distance' => round((float)$closest->distance, 4),
        ];
    }

    private function toHouseholdMemberPayload(User $member): array
    {
        return [
            'id' => (int) $member->id,
            'name' => (string) $member->name,
            'email' => (string) $member->email,
            'must_change_password' => (bool) $member->must_change_password,
            'role' => (string) ($member->pivot->role ?? User::ROLE_CHILD),
            'nickname' => (string) ($member->pivot->nickname ?? $member->name),
        ];
    }

    private function buildMemberShareText(string $name, string $email, string $rawPassword): string
    {
        return "Bonjour {$name} !\n\n"
            . "Ton compte FamilyFlow est prêt.\n"
            . "Connecte-toi avec les identifiants suivants :\n"
            . "E)mail : {$email}\n"
            . "Mot de passe temporaire : {$rawPassword}\n\n"
            . "N'oublie pas de modifier ton mot de passe dès la première connexion.";
    }

    /**
     * @return array<int, array{id:int, name:string, role:string}>
     */
    private function resolveParentReplacementCandidates(Household $household, int $excludedUserId): array
    {
        return $household->users()
            ->select(['users.id', 'users.name'])
            ->where('users.id', '!=', $excludedUserId)
            ->orderByRaw("CASE WHEN household_user.role = ? THEN 0 ELSE 1 END", [User::ROLE_PARENT])
            ->orderBy('users.name')
            ->get()
            ->map(static fn(User $member): array => [
                'id' => (int) $member->id,
                'name' => (string) $member->name,
                'role' => (string) ($member->pivot->role ?? User::ROLE_CHILD),
            ])
            ->values()
            ->all();
    }

    private function resolveEditableHousehold(Request $request): Household
    {
        $user = $this->resolveAuthenticatedUser($request);
        $selectedHousehold = $this->resolveCurrentHousehold($user, $request);

        if ($selectedHousehold) {
            $selectedRole = (string) ($selectedHousehold->pivot->role ?? User::ROLE_CHILD);
            if ($selectedRole === User::ROLE_PARENT) {
                return $selectedHousehold;
            }
        }

        $firstParentHousehold = $user->households()->wherePivot('role', User::ROLE_PARENT)->first();
        if ($firstParentHousehold) {
            return $firstParentHousehold;
        }

        if ($selectedHousehold) {
            return $selectedHousehold;
        }

        throw ValidationException::withMessages([
            'household' => ['Aucun foyer trouvé pour cet utilisateur.'],
        ]);
    }

    private function resolveAuthenticatedUser(Request $request): User
    {
        $user = $request->user();
        if (!$user instanceof User) {
            throw ValidationException::withMessages([
                'user' => ['Utilisateur authentifié introuvable.'],
            ]);
        }

        return $user;
    }

    private function generateUniqueHouseholdEmail(string $name): string
    {
        $cleanName = Str::slug($name);

        do {
            $randomCode = Str::lower(Str::random(4));
            $email = "{$cleanName}.{$randomCode}@family.app";
        } while (User::where('email', $email)->exists());

        return $email;
    }

}

