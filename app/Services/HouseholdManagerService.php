<?php

namespace App\Services;

use App\Models\BudgetSetting;
use App\Models\DietaryTag;
use App\Models\Household;
use App\Models\HouseholdSetting;
use App\Models\MealSetting;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Models\UserNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HouseholdManagerService
{
    public function __construct(
        private readonly RealtimePublisher $realtimePublisher,
    ) {
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function register(User $owner, array $validated): array
    {
        $householdName = trim((string) ($validated['household_name'] ?? $validated['name'] ?? ''));
        if ($householdName === '') {
            throw ValidationException::withMessages([
                'household_name' => ['Le nom du foyer est obligatoire.'],
            ]);
        }

        $modules = $this->normalizeModuleConfiguration($validated);
        $members = $this->normalizeMembers($validated);
        $this->validateMembersBudgetConfiguration($members, (bool) $modules['budget']['enabled']);
        $this->validateTasksConfiguration($modules['tasks']);

        return DB::transaction(function () use ($householdName, $modules, $members, $owner): array {
            $household = Household::create([
                'name' => $householdName,
                'is_setup_completed' => false,
            ]);
            
            $household->users()->attach($owner->id, [
                'role' => User::ROLE_PARENT,
                'nickname' => $owner->name ?? 'Admin',
            ]);

            $createdMembers = [];
            foreach ($members as $member) {
                $createdMembers[] = $this->createHouseholdMember(
                    $household,
                    $member,
                    (bool) $modules['budget']['enabled'],
                    $owner
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

            return [
                'status' => 201,
                'payload' => [
                    'message' => 'Foyer créé et configuré avec succès.',
                    'household' => $household,
                    'household_settings' => $householdSettings,
                    'meal_settings' => $mealSettings,
                    'created_members' => $createdMembers,
                    'created_task_templates' => $createdTaskTemplates,
                ],
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function inviteMember(Household $household, User $inviter, array $validated): array
    {
        $name = trim((string) ($validated['name'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => ['Le nom du membre est obligatoire.'],
            ]);
        }

        $providedEmail = $this->normalizeEmailInput($validated['email'] ?? null) ?? '';
        $finalEmail = $providedEmail === ''
            ? $this->generateUniqueHouseholdEmail($name)
            : $providedEmail;
        $memberRole = (string) ($validated['role'] ?? User::ROLE_CHILD);
        $rawPassword = Str::random(10);

        return DB::transaction(function () use (
            $household,
            $name,
            $finalEmail,
            $memberRole,
            $rawPassword,
            $inviter
        ): array {
            $existingUser = User::query()
                ->whereRaw('LOWER(email) = ?', [mb_strtolower($finalEmail)])
                ->first();

            if ($existingUser) {
                if ((int) $existingUser->id === (int) $inviter->id) {
                    throw ValidationException::withMessages([
                        'email' => ['Vous ne pouvez pas vous inviter vous-même.'],
                    ]);
                }

                $alreadyMember = $household->users()
                    ->where('users.id', $existingUser->id)
                    ->exists();
                if ($alreadyMember) {
                    throw ValidationException::withMessages([
                        'email' => ['Cet utilisateur fait déjà partie du foyer.'],
                    ]);
                }

                $invitationNotification = UserNotification::query()->create([
                    'household_id' => $household->id,
                    'user_id' => $existingUser->id,
                    'type' => 'household_invite',
                    'title' => 'Invitation de foyer',
                    'body' => sprintf(
                        '%s vous invite a rejoindre le foyer %s.',
                        (string) ($inviter->name ?? 'Un parent'),
                        (string) $household->name
                    ),
                    'data' => [
                        'household_id' => (int) $household->id,
                        'household_name' => (string) $household->name,
                        'inviter_user_id' => (int) $inviter->id,
                        'inviter_name' => (string) ($inviter->name ?? 'Parent'),
                        'invited_role' => $memberRole,
                        'status' => 'pending',
                    ],
                ]);

                DB::afterCommit(function () use ($invitationNotification, $household, $inviter, $existingUser, $memberRole): void {
                    $this->realtimePublisher->publishUser(
                        userId: (int) $existingUser->id,
                        module: 'notifications',
                        type: 'household_invite_created',
                        payload: [
                            'notification_id' => (int) $invitationNotification->id,
                            'household_id' => (int) $household->id,
                            'household_name' => (string) $household->name,
                            'inviter_user_id' => (int) $inviter->id,
                            'inviter_name' => (string) ($inviter->name ?? 'Parent'),
                            'invited_role' => (string) $memberRole,
                        ],
                    );
                });

                return [
                    'status' => 202,
                    'payload' => [
                        'message' => 'Invitation envoyée.',
                        'invitation' => [
                            'status' => 'pending',
                            'invited_user_id' => (int) $existingUser->id,
                            'invited_email' => (string) $existingUser->email,
                            'household_id' => (int) $household->id,
                            'household_name' => (string) $household->name,
                            'role' => $memberRole,
                        ],
                    ],
                ];
            }

            $newUser = User::create([
                'name' => $name,
                'email' => $finalEmail,
                'password' => Hash::make($rawPassword),
                'must_change_password' => true,
            ]);

            $household->users()->attach($newUser->id, [
                'role' => $memberRole,
                'nickname' => $name,
            ]);

            if ($memberRole === User::ROLE_CHILD) {
                BudgetSetting::query()->firstOrCreate(
                    [
                        'household_id' => $household->id,
                        'user_id' => $newUser->id,
                    ],
                    [
                        'base_amount' => 0,
                        'recurrence' => 'weekly',
                        'reset_day' => 1,
                        'allow_advances' => false,
                        'max_advance_amount' => 0,
                    ]
                );
            }

            $member = $household->users()
                ->where('users.id', $newUser->id)
                ->firstOrFail();

            return [
                'status' => 201,
                'payload' => [
                    'message' => 'Compte créé avec succès',
                    'user' => $newUser,
                    'member' => $this->toHouseholdMemberPayload($member),
                    'generated_password' => $rawPassword,
                    'generated_email' => $finalEmail,
                    'share_text' => $this->buildMemberShareText($name, $finalEmail, $rawPassword),
                ],
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function updateConfiguration(Household $household, User $user, array $validated): array
    {
        $updatedByUserId = (int) $user->id;
        $householdName = trim((string) ($validated['household_name'] ?? $validated['name'] ?? $household->name));
        if ($householdName === '') {
            throw ValidationException::withMessages([
                'household_name' => ['Le nom du foyer est obligatoire.'],
            ]);
        }

        $modules = $this->normalizeModuleConfiguration($validated);
        $this->validateTasksConfiguration($modules['tasks']);

        $householdUpdateData = ['name' => $householdName];
        if (array_key_exists('is_setup_completed', $validated)) {
            $householdUpdateData['is_setup_completed'] = (bool) $validated['is_setup_completed'];
        }

return DB::transaction(function () use ($household, $householdUpdateData, $modules, $updatedByUserId): array {

            $household->update($householdUpdateData);

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
                $updatedTaskTemplates = $this->upsertTaskTemplates(
                    $household,
                    $modules['tasks']['settings']['templates'] ?? []
                );
            }

            $enabledModules = [
                'meals' => (bool) $modules['meals']['enabled'],
                'tasks' => (bool) $modules['tasks']['enabled'],
                'budget' => (bool) $modules['budget']['enabled'],
                'calendar' => (bool) $modules['calendar']['enabled'],
            ];

            DB::afterCommit(function () use ($household, $householdUpdateData, $enabledModules, $updatedByUserId): void {
                $this->publishHouseholdRealtime(
                    householdId: (int) $household->id,
                    type: 'config_updated',
                    payload: [
                        'household_name' => (string) $householdUpdateData['name'],
                        'is_setup_completed' => (bool) $household->is_setup_completed, 
                        'modules' => [
                            'meals' => ['enabled' => (bool) $enabledModules['meals']],
                            'tasks' => ['enabled' => (bool) $enabledModules['tasks']],
                            'budget' => ['enabled' => (bool) $enabledModules['budget']],
                            'calendar' => ['enabled' => (bool) $enabledModules['calendar']],
                        ],
                        'updated_by_user_id' => $updatedByUserId,
                        'updated_at' => now()->toIso8601String(),
                    ],
                );
            });

            return [
                'status' => 200,
                'payload' => [
                    'message' => 'Configuration du foyer mise à jour.',
                    'household' => $household->fresh(),
                    'household_settings' => $householdSettings,
                    'meal_settings' => $mealSettings,
                    'updated_task_templates' => $updatedTaskTemplates,
                ],
            ];
        });
    }

    /**
     * @return array<int, int>
     */
    public function normalizeTaskRecurrenceDaysForConfig(mixed $value): array
    {
        return $this->normalizeTaskRecurrenceDays($value);
    }

    public function normalizeRotationCycleWeeksForConfig(mixed $value): int
    {
        return $this->normalizeRotationCycleWeeks($value);
    }

    public function normalizeIsoWeekDayForConfig(mixed $value, int $default = 1): int
    {
        return $this->normalizeIsoWeekDay($value, $default);
    }

    public function resolveCustodyHomeWeekStartForConfig(bool $isEnabled, mixed $rawDate, int $changeDay): ?string
    {
        return $this->resolveCustodyHomeWeekStart($isEnabled, $rawDate, $changeDay);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function publishHouseholdRealtime(int $householdId, string $type, array $payload = []): void
    {
        $this->realtimePublisher->publishHousehold(
            householdId: $householdId,
            module: 'household',
            type: $type,
            payload: $payload + ['household_id' => $householdId],
        );
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
                    "members.$index.email" => ['Deux membres ne peuvent pas partager le même e-mail.'],
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
                'members' => ["Le module Budget exige au moins un membre avec le rôle 'enfant'."],
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
                        "members.$index.budget.$key" => ["Le champ budget '$key' ne peut pas être vide."],
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
        $alternatingCustodyEnabled = (bool)($tasksSettingsConfig['alternating_custody_enabled'] ?? false);
        $custodyChangeDay = $this->normalizeIsoWeekDay($tasksSettingsConfig['custody_change_day'] ?? 5, 5);
        $custodyHomeWeekStart = $this->resolveCustodyHomeWeekStart(
            $alternatingCustodyEnabled,
            $tasksSettingsConfig['custody_home_week_start'] ?? null,
            $custodyChangeDay
        );
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
            $normalizedRecurrence = in_array($recurrence, ['daily', 'weekly', 'monthly', 'once'], true)
                ? $recurrence
                : 'weekly';
            $recurrenceDays = $this->normalizeTaskRecurrenceDays($template['recurrence_days'] ?? []);
            if (!in_array($normalizedRecurrence, ['daily', 'weekly'], true)) {
                $recurrenceDays = [];
            }

            $rotationEnabled = (bool)($template['is_rotation'] ?? false);
            $rotationCycleWeeks = $rotationEnabled
                ? $this->normalizeRotationCycleWeeks($template['rotation_cycle_weeks'] ?? 1)
                : 1;
            $interHouseholdAlternating = (bool)($template['is_inter_household_alternating'] ?? false);
            $interHouseholdWeekStart = $this->resolveInterHouseholdWeekStart(
                $interHouseholdAlternating,
                $template['inter_household_week_start'] ?? null
            );

            $fixedUserId = null;
            if (isset($template['fixed_user_id']) && is_numeric($template['fixed_user_id'])) {
                $parsedUserId = (int)$template['fixed_user_id'];
                if ($parsedUserId > 0) {
                    $fixedUserId = $parsedUserId;
                }
            }

            $templateId = null;
            if (isset($template['id']) && is_numeric($template['id'])) {
                $parsedTemplateId = (int)$template['id'];
                if ($parsedTemplateId > 0) {
                    $templateId = $parsedTemplateId;
                }
            }

            $taskTemplates[] = [
                'id' => $templateId,
                'name' => $name,
                'description' => $description !== '' ? $description : null,
                'recurrence' => $normalizedRecurrence,
                'recurrence_days' => $recurrenceDays,
                'is_rotation' => $rotationEnabled,
                'rotation_cycle_weeks' => $rotationCycleWeeks,
                'is_inter_household_alternating' => $interHouseholdAlternating,
                'inter_household_week_start' => $interHouseholdWeekStart,
                'fixed_user_id' => $fixedUserId,
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
                    'alternating_custody_enabled' => $alternatingCustodyEnabled,
                    'custody_change_day' => $custodyChangeDay,
                    'custody_home_week_start' => $custodyHomeWeekStart,
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
            'modules.meals.settings.poll_day' => ['Le jour du sondage doit être compris entre 1 et 7.'],
        ]);
    }

    private function normalizePollTime(mixed $value): string
    {
        if (!is_string($value)) {
            throw ValidationException::withMessages([
                'modules.meals.settings.poll_time' => ['L\'heure du sondage doit être au format HH:MM.'],
            ]);
        }

        $normalized = trim($value);
        $date = \DateTime::createFromFormat('H:i', $normalized);

        if (!$date || $date->format('H:i') !== $normalized) {
            throw ValidationException::withMessages([
                'modules.meals.settings.poll_time' => ['L\'heure du sondage doit être au format HH:MM.'],
            ]);
        }

        return $normalized;
    }

    private function createHouseholdMember(Household $household, array $member, bool $budgetEnabled, User $inviter): array
    {
        $name = trim((string)$member['name']);
        $role = (string)$member['role'];

        $finalEmail = isset($member['email']) && trim((string)$member['email']) !== ''
            ? trim((string)$member['email'])
            : $this->generateUniqueHouseholdEmail($name);

        $existingUser = User::query()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($finalEmail)])
            ->first();
        if ($existingUser) {
            if ((int) $existingUser->id === (int) $inviter->id) {
                throw ValidationException::withMessages([
                    'members' => ['Vous ne pouvez pas vous ajouter comme membre invité.'],
                ]);
            }

            $invitationNotification = UserNotification::query()->create([
                'household_id' => $household->id,
                'user_id' => $existingUser->id,
                'type' => 'household_invite',
                'title' => 'Invitation de foyer',
                'body' => sprintf(
                    '%s vous invite a rejoindre le foyer %s.',
                    (string) ($inviter->name ?? 'Un parent'),
                    (string) $household->name
                ),
                'data' => [
                    'household_id' => (int) $household->id,
                    'household_name' => (string) $household->name,
                    'inviter_user_id' => (int) $inviter->id,
                    'inviter_name' => (string) ($inviter->name ?? 'Parent'),
                    'invited_role' => $role,
                    'status' => 'pending',
                ],
            ]);

            DB::afterCommit(function () use ($invitationNotification, $household, $inviter, $existingUser, $role): void {
                $this->realtimePublisher->publishUser(
                    userId: (int) $existingUser->id,
                    module: 'notifications',
                    type: 'household_invite_created',
                    payload: [
                        'notification_id' => (int) $invitationNotification->id,
                        'household_id' => (int) $household->id,
                        'household_name' => (string) $household->name,
                        'inviter_user_id' => (int) $inviter->id,
                        'inviter_name' => (string) ($inviter->name ?? 'Parent'),
                        'invited_role' => (string) $role,
                    ],
                );
            });

            return [
                'id' => (int) $existingUser->id,
                'name' => (string) $existingUser->name,
                'role' => $role,
                'invitation_status' => 'pending',
                'invited_email' => (string) $existingUser->email,
            ];
        }

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
            'share_text' => $this->buildMemberShareText($name, $finalEmail, $rawPassword),
        ];
    }

    private function createTaskTemplates(Household $household, array $templates): array
    {
        $createdTemplates = [];

        foreach ($templates as $template) {
            $taskTemplate = TaskTemplate::create($this->buildTaskTemplateAttributes($household, $template));
            $createdTemplates[] = $this->toTaskTemplateConfigPayload($taskTemplate);
        }

        return $createdTemplates;
    }

    private function upsertTaskTemplates(Household $household, array $templates): array
    {
        $upsertedTemplates = [];

        foreach ($templates as $template) {
            if (!is_array($template)) {
                continue;
            }

            $templateId = isset($template['id']) && is_numeric($template['id'])
                ? (int)$template['id']
                : null;

            $taskTemplate = null;
            if ($templateId && $templateId > 0) {
                $taskTemplate = TaskTemplate::query()
                    ->where('household_id', $household->id)
                    ->where('id', $templateId)
                    ->first();
            }

            if ($taskTemplate) {
                $taskTemplate->update($this->buildTaskTemplateAttributes($household, $template, false));
            } else {
                $taskTemplate = TaskTemplate::create($this->buildTaskTemplateAttributes($household, $template));
            }

            $upsertedTemplates[] = $this->toTaskTemplateConfigPayload($taskTemplate);
        }

        return $upsertedTemplates;
    }

    private function buildTaskTemplateAttributes(
        Household $household,
        array $template,
        bool $includeHouseholdId = true
    ): array {
        $fixedUserId = null;
        if (isset($template['fixed_user_id']) && is_numeric($template['fixed_user_id'])) {
            $candidateUserId = (int)$template['fixed_user_id'];
            if (
                $candidateUserId > 0
                && $household->users()->where('users.id', $candidateUserId)->exists()
            ) {
                $fixedUserId = $candidateUserId;
            }
        }

        $recurrenceDays = $this->normalizeTaskRecurrenceDays($template['recurrence_days'] ?? []);
        $recurrence = (string)($template['recurrence'] ?? 'weekly');
        if (!in_array($recurrence, ['daily', 'weekly'], true)) {
            $recurrenceDays = [];
        }

        $rotationEnabled = (bool)($template['is_rotation'] ?? false);
        $interHouseholdAlternating = (bool)($template['is_inter_household_alternating'] ?? false);

        $attributes = [
            'name' => (string)($template['name'] ?? ''),
            'description' => $template['description'] ?? null,
            'recurrence' => $recurrence,
            'recurrence_days' => count($recurrenceDays) > 0 ? $recurrenceDays : null,
            'is_rotation' => $rotationEnabled,
            'rotation_cycle_weeks' => $rotationEnabled
                ? $this->normalizeRotationCycleWeeks($template['rotation_cycle_weeks'] ?? 1)
                : 1,
            'is_inter_household_alternating' => $interHouseholdAlternating,
            'inter_household_week_start' => $this->resolveInterHouseholdWeekStart(
                $interHouseholdAlternating,
                $template['inter_household_week_start'] ?? null
            ),
            'fixed_user_id' => $fixedUserId,
        ];

        if ($includeHouseholdId) {
            $attributes['household_id'] = $household->id;
        }

        return $attributes;
    }

    private function toTaskTemplateConfigPayload(TaskTemplate $taskTemplate): array
    {
        return [
            'id' => $taskTemplate->id,
            'name' => $taskTemplate->name,
            'recurrence' => $taskTemplate->recurrence,
            'recurrence_days' => $this->normalizeTaskRecurrenceDays($taskTemplate->recurrence_days),
            'is_rotation' => (bool)$taskTemplate->is_rotation,
            'rotation_cycle_weeks' => $this->normalizeRotationCycleWeeks($taskTemplate->rotation_cycle_weeks ?? 1),
            'is_inter_household_alternating' => (bool)($taskTemplate->is_inter_household_alternating ?? false),
            'inter_household_week_start' => optional($taskTemplate->inter_household_week_start)->toDateString(),
            'fixed_user_id' => $taskTemplate->fixed_user_id ? (int)$taskTemplate->fixed_user_id : null,
        ];
    }

    /**
     * @return array<int, int>
     */
    private function normalizeTaskRecurrenceDays(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $days = [];
        foreach ($value as $dayValue) {
            $day = (int)$dayValue;
            if ($day < 1 || $day > 7) {
                continue;
            }
            if (!in_array($day, $days, true)) {
                $days[] = $day;
            }
        }

        sort($days);

        return $days;
    }

    private function normalizeRotationCycleWeeks(mixed $value): int
    {
        $parsed = (int)$value;
        if (!in_array($parsed, [1, 2], true)) {
            return 1;
        }

        return $parsed;
    }

    private function normalizeIsoWeekDay(mixed $value, int $default = 1): int
    {
        $parsed = (int)$value;
        if ($parsed < 1 || $parsed > 7) {
            return $default;
        }

        return $parsed;
    }

    private function resolveCustodyHomeWeekStart(bool $isEnabled, mixed $rawDate, int $changeDay): ?string
    {
        if (!$isEnabled) {
            return null;
        }

        $baseDate = is_string($rawDate) && trim($rawDate) !== ''
            ? Carbon::createFromFormat('Y-m-d', trim($rawDate))->startOfDay()
            : now()->startOfDay();
        $startOfWeek = $this->startOfCustomWeek($baseDate, $changeDay);

        return $startOfWeek->toDateString();
    }

    private function startOfCustomWeek(Carbon $date, int $startDayIso): Carbon
    {
        $normalized = $date->copy()->startOfDay();
        $delta = ((int)$normalized->dayOfWeekIso - $startDayIso + 7) % 7;

        return $normalized->subDays($delta);
    }

    private function resolveInterHouseholdWeekStart(bool $isEnabled, mixed $rawWeekStart): ?string
    {
        if (!$isEnabled) {
            return null;
        }

        $startDate = is_string($rawWeekStart) && trim($rawWeekStart) !== ''
            ? Carbon::createFromFormat('Y-m-d', trim($rawWeekStart))->startOfDay()
            : now()->startOfDay();

        return $startDate
            ->startOfWeek(Carbon::MONDAY)
            ->toDateString();
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
            . "E-mail : {$email}\n"
            . "Mot de passe temporaire : {$rawPassword}\n\n"
            . "N'oublie pas de modifier ton mot de passe dès la première connexion.";
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

    private function normalizeEmailInput(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalized = mb_strtolower(trim($value));
        return $normalized !== '' ? $normalized : null;
    }
}
