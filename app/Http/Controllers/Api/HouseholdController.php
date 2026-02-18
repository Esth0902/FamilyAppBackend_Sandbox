<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BudgetSetting;
use App\Models\DietaryTag;
use App\Models\Household;
use App\Models\HouseholdSetting;
use App\Models\MealPoll;
use App\Models\MealPollVote;
use App\Models\MealSetting;
use App\Models\TaskTemplate;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenAI\Laravel\Facades\OpenAI;

class HouseholdController extends Controller
{
    private const DIETARY_TAG_TYPES = ['diet', 'allergen', 'dislike', 'restriction', 'cuisine_rule'];
    private const DIETARY_TAG_SIMILARITY_THRESHOLD = 0.10;

    public function store(Request $request)
    {
        if ($request->user()->households()->exists()) {
            return response()->json(['message' => 'Vous avez deja un foyer.'], 403);
        }

        $validated = $request->validate([
            'household_name' => 'nullable|string|max:255',
            'name' => 'nullable|string|max:255',

            'members' => 'nullable|array',
            'members.*.name' => 'required|string|max:255',
            'members.*.role' => 'required|in:parent,enfant',
            'members.*.email' => 'nullable|email|unique:users,email',
            'members.*.budget' => 'nullable|array',
            'members.*.budget.base_amount' => 'nullable|numeric|min:0',
            'members.*.budget.recurrence' => 'nullable|in:weekly,monthly',
            'members.*.budget.reset_day' => 'nullable|integer|min:1|max:31',
            'members.*.budget.allow_advances' => 'nullable|boolean',
            'members.*.budget.max_advance_amount' => 'nullable|numeric|min:0',

            // Compatibilite temporaire avec l'ancien setup mobile.
            'children_profiles' => 'nullable|array',
            'children_profiles.*' => 'nullable|string|max:255',
            'settings' => 'nullable|array',
            'poll_day' => 'nullable',
            'poll_time' => 'nullable|string',
            'poll_duration' => 'nullable|integer|min:1|max:168',

            'modules' => 'nullable|array',
            'modules.meals.enabled' => 'nullable|boolean',
            'modules.meals.options' => 'nullable|array',
            'modules.meals.options.recipes' => 'nullable|boolean',
            'modules.meals.options.polls' => 'nullable|boolean',
            'modules.meals.options.shopping_list' => 'nullable|boolean',
            'modules.meals.settings' => 'nullable|array',
            'modules.meals.settings.poll_day' => 'nullable',
            'modules.meals.settings.poll_time' => 'nullable|string',
            'modules.meals.settings.poll_duration' => 'nullable|integer|min:1|max:168',
            'modules.meals.settings.max_votes_per_user' => 'nullable|integer|min:1|max:20',
            'modules.meals.settings.default_servings' => 'nullable|integer|min:1|max:30',
            'modules.meals.settings.dietary_tags' => 'nullable|array',
            'modules.meals.settings.dietary_tags.*' => 'nullable|string|max:120',

            'modules.tasks.enabled' => 'nullable|boolean',
            'modules.tasks.settings' => 'nullable|array',
            'modules.tasks.settings.reminders_enabled' => 'nullable|boolean',
            'modules.tasks.settings.templates' => 'nullable|array',
            'modules.tasks.settings.templates.*.name' => 'required|string|max:255',
            'modules.tasks.settings.templates.*.description' => 'nullable|string|max:1000',
            'modules.tasks.settings.templates.*.recurrence' => 'required|in:daily,weekly,monthly',
            'modules.tasks.settings.templates.*.is_rotation' => 'nullable|boolean',

            'modules.calendar.enabled' => 'nullable|boolean',
            'modules.calendar.settings' => 'nullable|array',

            'modules.budget.enabled' => 'nullable|boolean',
            'modules.budget.settings' => 'nullable|array',
        ]);

        $householdName = trim((string)($validated['household_name'] ?? $validated['name'] ?? ''));
        if ($householdName === '') {
            throw ValidationException::withMessages([
                'household_name' => ['Le nom du foyer est obligatoire.'],
            ]);
        }

        $modules = $this->normalizeModuleConfiguration($validated);
        $members = $this->normalizeMembers($validated);
        $this->validateMembersBudgetConfiguration($members, $modules['budget']['enabled']);
        $this->validateTasksConfiguration($modules['tasks']);

        $owner = $request->user();

        return DB::transaction(function () use ($householdName, $modules, $members, $owner) {
            $household = Household::create(['name' => $householdName]);

            $household->users()->attach($owner->id, [
                'role' => User::ROLE_PARENT,
                'nickname' => $owner->name ?? 'Admin',
            ]);

            $createdMembers = [];
            foreach ($members as $member) {
                $createdMembers[] = $this->createHouseholdMember(
                    $household,
                    $member,
                    $modules['budget']['enabled']
                );
            }

            $createdTaskTemplates = [];
            if ($modules['tasks']['enabled']) {
                $createdTaskTemplates = $this->createTaskTemplates(
                    $household,
                    $modules['tasks']['settings']['templates'] ?? []
                );
            }

            $householdSettings = HouseholdSetting::create([
                'household_id' => $household->id,
                'has_meals' => $modules['meals']['enabled'],
                'has_shopping_list' => $modules['meals']['shopping_list'],
                'has_tasks' => $modules['tasks']['enabled'],
                'has_budget' => $modules['budget']['enabled'],
                'has_calendar' => $modules['calendar']['enabled'],
                'tasks_config' => $modules['tasks']['settings'],
                'calendar_config' => $modules['calendar']['settings'],
                'budget_config' => $modules['budget']['settings'],
            ]);

            $mealSettings = MealSetting::create([
                'household_id' => $household->id,
                'poll_day' => $modules['meals']['poll_day'],
                'poll_time' => $modules['meals']['poll_time'],
                'poll_duration' => $modules['meals']['poll_duration'],
                'enable_recipes' => $modules['meals']['recipes'],
                'enable_polls' => $modules['meals']['polls'],
                'enable_shopping_list' => $modules['meals']['shopping_list'],
                'auto_generate_shopping_list' => $modules['meals']['shopping_list'],
                'max_votes_per_user' => $modules['meals']['max_votes_per_user'],
                'default_servings' => $modules['meals']['default_servings'],
            ]);

            $this->syncDietaryTags($household, $modules['meals']['dietary_tags']);

            return response()->json([
                'message' => 'Foyer cree et configure avec succes.',
                'household' => $household,
                'household_settings' => $householdSettings,
                'meal_settings' => $mealSettings,
                'created_members' => $createdMembers,
                'created_task_templates' => $createdTaskTemplates,
            ], 201);
        });
    }

    public function addMember(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|unique:users,email',
            'role' => 'required|in:parent,enfant',
        ]);

        $adminUser = $request->user();

        $household = $adminUser->households()->wherePivot('role', User::ROLE_PARENT)->first();
        if (!$household) {
            $household = $adminUser->households()->firstOrFail();
        }

        $finalEmail = empty($validated['email'])
            ? $this->generateUniqueHouseholdEmail($validated['name'])
            : $validated['email'];

        $rawPassword = Str::random(10);

        return DB::transaction(function () use ($validated, $finalEmail, $household, $rawPassword) {
            $newUser = User::create([
                'name' => $validated['name'],
                'email' => $finalEmail,
                'password' => Hash::make($rawPassword),
                'must_change_password' => true,
            ]);

            $household->users()->attach($newUser->id, [
                'role' => $validated['role'],
                'nickname' => $validated['name'],
            ]);

            if ($validated['role'] === User::ROLE_CHILD) {
                BudgetSetting::create([
                    'household_id' => $household->id,
                    'user_id' => $newUser->id,
                    'base_amount' => 0,
                ]);
            }

            $shareText = "Bonjour {$validated['name']} !\n\n"
                . "Ton compte FamilyApp est pret.\n"
                . "Email : {$finalEmail}\n"
                . "Mot de passe temporaire : {$rawPassword}\n\n"
                . "Connecte-toi puis modifie ton mot de passe des la premiere connexion.";

            return response()->json([
                'message' => 'Compte cree avec succes',
                'user' => $newUser,
                'generated_password' => $rawPassword,
                'generated_email' => $finalEmail,
                'share_text' => $shareText,
            ], 201);
        });
    }

    public function dashboard(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifie.'], 401);
        }
        $household = $user->households()->first();

        if (!$household) {
            return response()->json(['message' => 'Aucun foyer', 'requires_setup' => true]);
        }

        $household->load('users');

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

        return response()->json([
            'household_name' => $household->name,
            'members' => $household->users,
            'active_poll' => $openPolls->first(),
            'polls_open' => $openPolls,
            'polls_closed' => $closedPolls,
            'polls' => $pollsPayload,
            'favorite_recipes' => $favoriteRecipes,
        ]);
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
        $household = $this->resolveEditableHousehold($request->user());
        $household->load(['settings', 'mealSettings', 'dietaryTags']);

        $settings = $household->settings;
        $mealSettings = $household->mealSettings;
        $tasksConfig = is_array($settings?->tasks_config) ? $settings->tasks_config : [];
        $calendarConfig = is_array($settings?->calendar_config) ? $settings->calendar_config : [];
        $budgetConfig = is_array($settings?->budget_config) ? $settings->budget_config : [];
        $taskTemplates = TaskTemplate::where('household_id', $household->id)
            ->orderBy('id')
            ->get();

        return response()->json([
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
                            'templates' => $taskTemplates->map(static function (TaskTemplate $template): array {
                                return [
                                    'name' => $template->name,
                                    'description' => $template->description,
                                    'recurrence' => $template->recurrence,
                                    'is_rotation' => (bool)$template->is_rotation,
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
        ]);
    }

    public function dietaryTags(Request $request)
    {
        $household = $this->resolveEditableHousehold($request->user());
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

    public function createDietaryTag(Request $request)
    {
        $household = $this->resolveEditableHousehold($request->user());
        $validated = $request->validate([
            'label' => 'required|string|min:2|max:120',
            'type' => 'required|string|in:' . implode(',', self::DIETARY_TAG_TYPES),
        ]);

        $label = trim((string)$validated['label']);
        $type = (string)$validated['type'];
        $key = Str::slug($label);

        if ($key === '') {
            throw ValidationException::withMessages([
                'label' => ['Le tag est invalide.'],
            ]);
        }

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

    public function updateConfig(Request $request)
    {
        $household = $this->resolveEditableHousehold($request->user());

        $validated = $request->validate([
            'household_name' => 'nullable|string|max:255',
            'name' => 'nullable|string|max:255',

            'modules' => 'required|array',
            'modules.meals.enabled' => 'nullable|boolean',
            'modules.meals.options' => 'nullable|array',
            'modules.meals.options.recipes' => 'nullable|boolean',
            'modules.meals.options.polls' => 'nullable|boolean',
            'modules.meals.options.shopping_list' => 'nullable|boolean',
            'modules.meals.settings' => 'nullable|array',
            'modules.meals.settings.poll_day' => 'nullable',
            'modules.meals.settings.poll_time' => 'nullable|string',
            'modules.meals.settings.poll_duration' => 'nullable|integer|min:1|max:168',
            'modules.meals.settings.max_votes_per_user' => 'nullable|integer|min:1|max:20',
            'modules.meals.settings.default_servings' => 'nullable|integer|min:1|max:30',
            'modules.meals.settings.dietary_tags' => 'nullable|array',
            'modules.meals.settings.dietary_tags.*' => 'nullable|string|max:120',

            'modules.tasks.enabled' => 'nullable|boolean',
            'modules.tasks.settings' => 'nullable|array',
            'modules.tasks.settings.reminders_enabled' => 'nullable|boolean',
            'modules.tasks.settings.templates' => 'nullable|array',
            'modules.tasks.settings.templates.*.name' => 'required|string|max:255',
            'modules.tasks.settings.templates.*.description' => 'nullable|string|max:1000',
            'modules.tasks.settings.templates.*.recurrence' => 'required|in:daily,weekly,monthly',
            'modules.tasks.settings.templates.*.is_rotation' => 'nullable|boolean',

            'modules.calendar.enabled' => 'nullable|boolean',
            'modules.calendar.settings' => 'nullable|array',

            'modules.budget.enabled' => 'nullable|boolean',
            'modules.budget.settings' => 'nullable|array',
        ]);

        $householdName = trim((string)($validated['household_name'] ?? $validated['name'] ?? $household->name));
        if ($householdName === '') {
            throw ValidationException::withMessages([
                'household_name' => ['Le nom du foyer est obligatoire.'],
            ]);
        }

        $modules = $this->normalizeModuleConfiguration($validated);
        $this->validateTasksConfiguration($modules['tasks']);

        return DB::transaction(function () use ($household, $householdName, $modules) {
            $household->update(['name' => $householdName]);

            $householdSettings = HouseholdSetting::updateOrCreate(
                ['household_id' => $household->id],
                [
                    'has_meals' => $modules['meals']['enabled'],
                    'has_shopping_list' => $modules['meals']['shopping_list'],
                    'has_tasks' => $modules['tasks']['enabled'],
                    'has_budget' => $modules['budget']['enabled'],
                    'has_calendar' => $modules['calendar']['enabled'],
                    'tasks_config' => $modules['tasks']['settings'],
                    'calendar_config' => $modules['calendar']['settings'],
                    'budget_config' => $modules['budget']['settings'],
                ]
            );

            $mealSettings = MealSetting::updateOrCreate(
                ['household_id' => $household->id],
                [
                    'poll_day' => $modules['meals']['poll_day'],
                    'poll_time' => $modules['meals']['poll_time'],
                    'poll_duration' => $modules['meals']['poll_duration'],
                    'enable_recipes' => $modules['meals']['recipes'],
                    'enable_polls' => $modules['meals']['polls'],
                    'enable_shopping_list' => $modules['meals']['shopping_list'],
                    'auto_generate_shopping_list' => $modules['meals']['shopping_list'],
                    'max_votes_per_user' => $modules['meals']['max_votes_per_user'],
                    'default_servings' => $modules['meals']['default_servings'],
                ]
            );

            $this->syncDietaryTags($household, $modules['meals']['dietary_tags']);

            $updatedTaskTemplates = [];
            if ($modules['tasks']['enabled']) {
                TaskTemplate::where('household_id', $household->id)->delete();
                $updatedTaskTemplates = $this->createTaskTemplates(
                    $household,
                    $modules['tasks']['settings']['templates'] ?? []
                );
            }

            return response()->json([
                'message' => 'Configuration du foyer mise a jour.',
                'household' => $household->fresh(),
                'household_settings' => $householdSettings,
                'meal_settings' => $mealSettings,
                'updated_task_templates' => $updatedTaskTemplates,
            ]);
        });
    }

    private function normalizeMembers(array $validated): array
    {
        $members = $validated['members'] ?? [];
        if (!empty($members)) {
            return $members;
        }

        $legacyChildren = $validated['children_profiles'] ?? [];
        $fromLegacy = [];

        foreach ($legacyChildren as $childName) {
            $name = trim((string)$childName);
            if ($name === '') {
                continue;
            }

            $fromLegacy[] = [
                'name' => $name,
                'role' => User::ROLE_CHILD,
            ];
        }

        return $fromLegacy;
    }

    private function validateMembersBudgetConfiguration(array $members, bool $budgetEnabled): void
    {
        $emails = [];
        foreach ($members as $index => $member) {
            $email = strtolower(trim((string)($member['email'] ?? '')));
            if ($email === '') {
                continue;
            }

            if (in_array($email, $emails, true)) {
                throw ValidationException::withMessages([
                    "members.$index.email" => ['Deux membres ne peuvent pas partager le meme email.'],
                ]);
            }
            $emails[] = $email;
        }

        if (!$budgetEnabled) {
            return;
        }

        $children = array_filter(
            $members,
            static fn(array $member): bool => ($member['role'] ?? null) === User::ROLE_CHILD
        );

        if (count($children) === 0) {
            throw ValidationException::withMessages([
                'members' => ["Le module Budget exige au moins un membre avec le role 'enfant'."],
            ]);
        }

        foreach ($members as $index => $member) {
            if (($member['role'] ?? null) !== User::ROLE_CHILD) {
                continue;
            }

            $budget = $member['budget'] ?? null;
            if (!is_array($budget)) {
                throw ValidationException::withMessages([
                    "members.$index.budget" => ['La configuration budget est obligatoire pour chaque enfant.'],
                ]);
            }

            $requiredBudgetKeys = ['base_amount', 'recurrence', 'reset_day', 'allow_advances', 'max_advance_amount'];
            foreach ($requiredBudgetKeys as $key) {
                if (!array_key_exists($key, $budget)) {
                    throw ValidationException::withMessages([
                        "members.$index.budget.$key" => ["Le champ budget '$key' est obligatoire pour chaque enfant."],
                    ]);
                }

                if ($budget[$key] === null) {
                    throw ValidationException::withMessages([
                        "members.$index.budget.$key" => ["Le champ budget '$key' ne peut pas etre vide."],
                    ]);
                }
            }
        }
    }

    private function normalizeModuleConfiguration(array $validated): array
    {
        $modulesInput = $validated['modules'] ?? [];
        $legacySettings = $validated['settings'] ?? [];

        $legacyModuleList = (is_array($modulesInput) && array_is_list($modulesInput)) ? $modulesInput : [];
        $moduleConfig = (is_array($modulesInput) && !array_is_list($modulesInput)) ? $modulesInput : [];
        $mealsConfig = is_array($moduleConfig['meals'] ?? null) ? $moduleConfig['meals'] : [];
        $mealsOptionsConfig = is_array($mealsConfig['options'] ?? null) ? $mealsConfig['options'] : [];
        $mealsSettingsConfig = is_array($mealsConfig['settings'] ?? null) ? $mealsConfig['settings'] : [];
        $tasksConfig = is_array($moduleConfig['tasks'] ?? null) ? $moduleConfig['tasks'] : [];
        $tasksSettingsConfig = is_array($tasksConfig['settings'] ?? null) ? $tasksConfig['settings'] : [];
        $calendarConfig = is_array($moduleConfig['calendar'] ?? null) ? $moduleConfig['calendar'] : [];
        $budgetConfig = is_array($moduleConfig['budget'] ?? null) ? $moduleConfig['budget'] : [];

        $mealsEnabled = $this->resolveModuleEnabled($moduleConfig, $legacySettings, $legacyModuleList, 'meals', true);
        $tasksEnabled = $this->resolveModuleEnabled($moduleConfig, $legacySettings, $legacyModuleList, 'tasks', false);
        $calendarEnabled = $this->resolveModuleEnabled($moduleConfig, $legacySettings, $legacyModuleList, 'calendar', false);
        $budgetEnabled = $this->resolveModuleEnabled($moduleConfig, $legacySettings, $legacyModuleList, 'budget', false);

        $recipesEnabled = $mealsEnabled && (bool)($mealsOptionsConfig['recipes'] ?? true);
        $pollsEnabled = $mealsEnabled && (bool)($mealsOptionsConfig['polls'] ?? true);

        $shoppingListOption = $mealsOptionsConfig['shopping_list'] ?? null;
        if ($shoppingListOption !== null) {
            $shoppingListEnabled = $mealsEnabled && (bool)$shoppingListOption;
        } elseif (array_key_exists('shopping_list', $legacySettings)) {
            $shoppingListEnabled = $mealsEnabled && (bool)$legacySettings['shopping_list'];
        } elseif (!empty($legacyModuleList)) {
            $shoppingListEnabled = $mealsEnabled && in_array('shopping_list', $legacyModuleList, true);
        } else {
            $shoppingListEnabled = $mealsEnabled;
        }

        $rawPollDay = $mealsSettingsConfig['poll_day'] ?? ($validated['poll_day'] ?? 5);
        $rawPollTime = $mealsSettingsConfig['poll_time'] ?? ($validated['poll_time'] ?? '10:00');
        $rawPollDuration = $mealsSettingsConfig['poll_duration'] ?? ($validated['poll_duration'] ?? 24);
        $maxVotes = (int)($mealsSettingsConfig['max_votes_per_user'] ?? 3);
        $defaultServings = (int)($mealsSettingsConfig['default_servings'] ?? 4);
        $dietaryTagsInput = is_array($mealsSettingsConfig['dietary_tags'] ?? null)
            ? $mealsSettingsConfig['dietary_tags']
            : [];
        $dietaryTags = [];
        foreach ($dietaryTagsInput as $dietaryTag) {
            if (!is_string($dietaryTag)) {
                continue;
            }

            $cleanTag = Str::slug(trim($dietaryTag));
            if ($cleanTag === '' || in_array($cleanTag, $dietaryTags, true)) {
                continue;
            }
            $dietaryTags[] = $cleanTag;
        }
        $taskTemplatesInput = is_array($tasksSettingsConfig['templates'] ?? null)
            ? $tasksSettingsConfig['templates']
            : [];
        $taskTemplates = [];

        foreach ($taskTemplatesInput as $template) {
            if (!is_array($template)) {
                continue;
            }

            $name = trim((string)($template['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $description = trim((string)($template['description'] ?? ''));
            $recurrence = strtolower(trim((string)($template['recurrence'] ?? 'weekly')));

            $taskTemplates[] = [
                'name' => $name,
                'description' => $description !== '' ? $description : null,
                'recurrence' => in_array($recurrence, ['daily', 'weekly', 'monthly'], true)
                    ? $recurrence
                    : 'weekly',
                'is_rotation' => (bool)($template['is_rotation'] ?? false),
            ];
        }

        return [
            'meals' => [
                'enabled' => $mealsEnabled,
                'recipes' => $recipesEnabled,
                'polls' => $pollsEnabled,
                'shopping_list' => $shoppingListEnabled,
                'poll_day' => $this->normalizePollDay($rawPollDay),
                'poll_time' => $this->normalizePollTime($rawPollTime),
                'poll_duration' => max(1, min((int)$rawPollDuration, 168)),
                'max_votes_per_user' => max(1, min($maxVotes, 20)),
                'default_servings' => max(1, min($defaultServings, 30)),
                'dietary_tags' => $dietaryTags,
            ],
            'tasks' => [
                'enabled' => $tasksEnabled,
                'settings' => [
                    'reminders_enabled' => (bool)($tasksSettingsConfig['reminders_enabled'] ?? true),
                    'templates' => $taskTemplates,
                ],
            ],
            'calendar' => [
                'enabled' => $calendarEnabled,
                'settings' => is_array($calendarConfig['settings'] ?? null)
                    ? $calendarConfig['settings']
                    : [],
            ],
            'budget' => [
                'enabled' => $budgetEnabled,
                'settings' => is_array($budgetConfig['settings'] ?? null)
                    ? $budgetConfig['settings']
                    : [],
            ],
        ];
    }

    private function validateTasksConfiguration(array $tasksModule): void
    {
        if (!($tasksModule['enabled'] ?? false)) {
            return;
        }

        $settings = is_array($tasksModule['settings'] ?? null) ? $tasksModule['settings'] : [];
        $templates = is_array($settings['templates'] ?? null) ? $settings['templates'] : [];

        if (count($templates) === 0) {
            throw ValidationException::withMessages([
                'modules.tasks.settings.templates' => [
                    'Ajoutez au moins un template de tache quand le module Taches menageres est active.',
                ],
            ]);
        }
    }

    private function resolveModuleEnabled(
        array $moduleConfig,
        array $legacySettings,
        array $legacyModuleList,
        string $moduleKey,
        bool $default
    ): bool {
        if (
            isset($moduleConfig[$moduleKey])
            && is_array($moduleConfig[$moduleKey])
            && array_key_exists('enabled', $moduleConfig[$moduleKey])
        ) {
            return (bool)$moduleConfig[$moduleKey]['enabled'];
        }

        if (array_key_exists($moduleKey, $legacySettings)) {
            return (bool)$legacySettings[$moduleKey];
        }

        if (!empty($legacyModuleList)) {
            return in_array($moduleKey, $legacyModuleList, true);
        }

        return $default;
    }

    private function normalizePollDay(mixed $value): int
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $day = (int)$value;
            if ($day >= 1 && $day <= 7) {
                return $day;
            }
        }

        if (is_string($value)) {
            $map = [
                'monday' => 1,
                'lundi' => 1,
                'tuesday' => 2,
                'mardi' => 2,
                'wednesday' => 3,
                'mercredi' => 3,
                'thursday' => 4,
                'jeudi' => 4,
                'friday' => 5,
                'vendredi' => 5,
                'saturday' => 6,
                'samedi' => 6,
                'sunday' => 7,
                'dimanche' => 7,
            ];

            $normalized = strtolower(trim($value));
            if (isset($map[$normalized])) {
                return $map[$normalized];
            }
        }

        throw ValidationException::withMessages([
            'modules.meals.settings.poll_day' => ['Le jour du sondage doit etre compris entre 1 et 7.'],
        ]);
    }

    private function normalizePollTime(mixed $value): string
    {
        if (!is_string($value)) {
            throw ValidationException::withMessages([
                'modules.meals.settings.poll_time' => ['L heure du sondage doit etre au format HH:MM.'],
            ]);
        }

        $normalized = trim($value);
        $date = \DateTime::createFromFormat('H:i', $normalized);

        if (!$date || $date->format('H:i') !== $normalized) {
            throw ValidationException::withMessages([
                'modules.meals.settings.poll_time' => ['L heure du sondage doit etre au format HH:MM.'],
            ]);
        }

        return $normalized;
    }

    private function createHouseholdMember(Household $household, array $member, bool $budgetEnabled): array
    {
        $name = trim((string)$member['name']);
        $role = (string)$member['role'];

        $finalEmail = isset($member['email']) && trim((string)$member['email']) !== ''
            ? trim((string)$member['email'])
            : $this->generateUniqueHouseholdEmail($name);

        $rawPassword = Str::random(10);
        $newUser = User::create([
            'name' => $name,
            'email' => $finalEmail,
            'password' => Hash::make($rawPassword),
            'must_change_password' => true,
        ]);

        $household->users()->attach($newUser->id, [
            'role' => $role,
            'nickname' => $name,
        ]);

        if ($budgetEnabled && $role === User::ROLE_CHILD) {
            $budget = $member['budget'];
            BudgetSetting::create([
                'household_id' => $household->id,
                'user_id' => $newUser->id,
                'base_amount' => (float)$budget['base_amount'],
                'recurrence' => (string)$budget['recurrence'],
                'reset_day' => (int)$budget['reset_day'],
                'allow_advances' => (bool)$budget['allow_advances'],
                'max_advance_amount' => (float)$budget['max_advance_amount'],
            ]);
        }

        return [
            'id' => $newUser->id,
            'name' => $newUser->name,
            'role' => $role,
            'generated_email' => $finalEmail,
            'generated_password' => $rawPassword,
            'share_text' => "Bonjour {$name} !\n\n"
                . "Ton compte FamilyApp est pret.\n"
                . "Email : {$finalEmail}\n"
                . "Mot de passe temporaire : {$rawPassword}\n\n"
                . "Connecte-toi puis modifie ton mot de passe des la premiere connexion.",
        ];
    }

    private function createTaskTemplates(Household $household, array $templates): array
    {
        $createdTemplates = [];

        foreach ($templates as $template) {
            $taskTemplate = TaskTemplate::create([
                'household_id' => $household->id,
                'name' => (string)$template['name'],
                'description' => $template['description'] ?? null,
                'recurrence' => (string)$template['recurrence'],
                'is_rotation' => (bool)($template['is_rotation'] ?? false),
                'fixed_user_id' => null,
            ]);

            $createdTemplates[] = [
                'id' => $taskTemplate->id,
                'name' => $taskTemplate->name,
                'recurrence' => $taskTemplate->recurrence,
                'is_rotation' => (bool)$taskTemplate->is_rotation,
            ];
        }

        return $createdTemplates;
    }

    /**
     * @return array<int, float>|null
     */
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

    private function syncDietaryTags(Household $household, array $dietaryTags): void
    {
        $normalizedKeys = [];

        foreach ($dietaryTags as $tagKey) {
            if (!is_string($tagKey)) {
                continue;
            }

            $normalizedKey = Str::slug(trim($tagKey));
            if ($normalizedKey === '') {
                continue;
            }
            $normalizedKeys[] = $normalizedKey;
        }

        if (count($normalizedKeys) === 0) {
            $household->dietaryTags()->sync([]);
            return;
        }

        $tagIds = DietaryTag::query()
            ->whereIn('key', array_values(array_unique($normalizedKeys)))
            ->pluck('id')
            ->all();

        $household->dietaryTags()->sync($tagIds);
    }

    private function resolveEditableHousehold(User $user): Household
    {
        $household = $user->households()->wherePivot('role', User::ROLE_PARENT)->first();
        if ($household) {
            return $household;
        }

        $household = $user->households()->first();
        if ($household) {
            return $household;
        }

        throw ValidationException::withMessages([
            'household' => ['Aucun foyer trouve pour cet utilisateur.'],
        ]);
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
