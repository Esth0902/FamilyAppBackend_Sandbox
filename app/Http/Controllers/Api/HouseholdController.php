<?php

namespace App\Http\Controllers\Api;

use App\Actions\Household\CreateDietaryTagAction;
use App\Actions\Household\GetHouseholdConfigAction;
use App\Actions\Household\GetHouseholdDashboardAction;
use App\Actions\Household\LeaveHouseholdAction;
use App\Actions\Household\RefreshMemberTemporaryAccessAction;
use App\Actions\Household\RemoveMemberAction;
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
use App\Models\DietaryTag;
use App\Models\Household;
use App\Support\JsonUtf8Sanitizer;
use App\Services\HouseholdDeletionService;
use App\Services\HouseholdManagerService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HouseholdController extends Controller
{
    use ResolvesHouseholdContext;

    private const DIETARY_TAG_TYPES = ['diet', 'allergen', 'dislike', 'restriction', 'cuisine_rule'];

    public function __construct(
        private readonly HouseholdDeletionService $householdDeletionService,
        private readonly HouseholdManagerService $householdManagerService,
        private readonly UpdateMemberAction $updateMemberAction,
        private readonly LeaveHouseholdAction $leaveHouseholdAction,
        private readonly RemoveMemberAction $removeMemberAction,
        private readonly RefreshMemberTemporaryAccessAction $refreshMemberTemporaryAccessAction,
        private readonly CreateDietaryTagAction $createDietaryTagAction,
        private readonly GetHouseholdDashboardAction $getHouseholdDashboardAction,
        private readonly GetHouseholdConfigAction $getHouseholdConfigAction,
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
        $this->removeMemberAction->execute($request->household(), $member);

        return response()->json([
            'message' => 'Membre supprimé du foyer.',
            'deleted_member_id' => (int) $member->id,
        ]);
    }

    public function refreshMemberTemporaryAccess(RefreshMemberAccessRequest $request, User $member)
    {
        $result = $this->refreshMemberTemporaryAccessAction->execute($request->household(), $member);

        return response()->json(JsonUtf8Sanitizer::sanitize([
            'message' => 'Nouvel accès temporaire généré.',
            'member' => $this->toHouseholdMemberPayload($result['member']),
            'generated_email' => (string) $result['generated_email'],
            'generated_password' => (string) $result['generated_password'],
            'share_text' => (string) $result['share_text'],
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

        $payload = $this->getHouseholdDashboardAction->execute($household);

        return response()->json(JsonUtf8Sanitizer::sanitize($payload));
    }

    public function config(Request $request)
    {
        $household = $this->resolveEditableHousehold($request);
        $payload = $this->getHouseholdConfigAction->execute($household);

        return response()->json(JsonUtf8Sanitizer::sanitize($payload));
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
        $result = $this->createDietaryTagAction->execute($request->household(), $request->validated());

        return response()->json(
            JsonUtf8Sanitizer::sanitize($result['payload']),
            (int) $result['status']
        );
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

}

