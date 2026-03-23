<?php

namespace App\Http\Controllers\Api;

use App\Actions\Household\{CreateDietaryTagAction, GetDietaryTagsAction, GetHouseholdConfigAction, GetHouseholdDashboardAction, GetHouseholdMembersAction, LeaveHouseholdAction, RefreshMemberTemporaryAccessAction, RemoveMemberAction, UpdateMemberAction};
use App\Http\Controllers\Controller;
use App\Http\Requests\Household\{AddHouseholdMemberRequest, CreateDietaryTagRequest, DeleteHouseholdMemberRequest, ListDietaryTagsRequest, ParentHouseholdRequest, RefreshMemberAccessRequest, ShowHouseholdConfigRequest, ShowHouseholdDashboardRequest, ShowHouseholdMembersRequest, StoreHouseholdRequest, UpdateHouseholdConfigRequest, UpdateHouseholdMemberRequest};
use App\Http\Resources\Household\{DietaryTagResource, HouseholdConfigResource, HouseholdMemberResource};
use App\Models\{DietaryTag, User};
use App\Services\{HouseholdDeletionService, HouseholdManagerService};
use App\Support\JsonUtf8Sanitizer;

class HouseholdController extends Controller
{
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
        private readonly GetHouseholdMembersAction $getHouseholdMembersAction,
        private readonly GetDietaryTagsAction $getDietaryTagsAction,
    ) {}

    public function store(StoreHouseholdRequest $request)
    {
        $result = $this->householdManagerService->register(owner: $request->actorOrFail(), validated: $request->validated());
        return response()->json(JsonUtf8Sanitizer::sanitize($result['payload']), (int) $result['status']);
    }

    public function members(ShowHouseholdMembersRequest $request)
    {
        $household = $request->household();
        $members = $this->getHouseholdMembersAction->execute($household);
        return response()->json(JsonUtf8Sanitizer::sanitize(['household' => ['id' => (int) $household->id, 'name' => (string) $household->name], 'permissions' => ['can_manage_members' => $request->householdRole() === User::ROLE_PARENT], 'members' => HouseholdMemberResource::collection($members)->resolve($request)]));
    }

    public function addMember(AddHouseholdMemberRequest $request)
    {
        $result = $this->householdManagerService->inviteMember(household: $request->household(), inviter: $request->actorOrFail(), validated: $request->validated());
        return response()->json(JsonUtf8Sanitizer::sanitize($result['payload']), (int) $result['status']);
    }

    public function updateMember(UpdateHouseholdMemberRequest $request, User $member)
    {
        $result = $this->updateMemberAction->execute(household: $request->household(), member: $member, validated: $request->validated());
        $payload = ['message' => 'Membre mis a jour.', 'member' => HouseholdMemberResource::make($result['member'])->resolve($request)];
        if (is_string($result['generated_email'] ?? null) && trim((string) $result['generated_email']) !== '') { $payload['generated_email'] = (string) $result['generated_email']; }
        return response()->json(JsonUtf8Sanitizer::sanitize($payload));
    }

    public function deleteMember(DeleteHouseholdMemberRequest $request, User $member)
    {
        $this->removeMemberAction->execute($request->household(), $member);
        return response()->json(['message' => 'Membre supprimé du foyer.', 'deleted_member_id' => (int) $member->id]);
    }

    public function refreshMemberTemporaryAccess(RefreshMemberAccessRequest $request, User $member)
    {
        $result = $this->refreshMemberTemporaryAccessAction->execute($request->household(), $member);
        $meta = HouseholdMemberResource::temporaryAccessMeta($result['member'], (string) $result['generated_password']);
        return response()->json(JsonUtf8Sanitizer::sanitize(['message' => 'Nouvel accès temporaire généré.', 'member' => HouseholdMemberResource::make($result['member'])->resolve($request), 'generated_email' => (string) ($meta['generated_email'] ?? ''), 'generated_password' => (string) ($meta['generated_password'] ?? ''), 'share_text' => (string) ($meta['share_text'] ?? '')]));
    }

    public function requestDeletion(ParentHouseholdRequest $request)
    {
        $result = $this->householdDeletionService->requestDeletion($request->household(), $request->actorOrFail());
        $scheduledFor = data_get($result, 'scheduled_for');
        return response()->json(JsonUtf8Sanitizer::sanitize(['message' => $scheduledFor ? 'La suppression du foyer est planifiée dans 24h.' : 'La demande de suppression a été envoyée aux autres parents.', 'deletion_request' => ['request_id' => (string) data_get($result, 'request_id', ''), 'status' => (string) data_get($result, 'status', 'pending_approvals'), 'scheduled_for' => is_string($scheduledFor) ? $scheduledFor : null, 'approvals_required' => (int) data_get($result, 'approvals_required', 0), 'approvals_received' => (int) data_get($result, 'approvals_received', 0)]]));
    }

    public function leave(ParentHouseholdRequest $request)
    {
        $user = $this->leaveHouseholdAction->execute(household: $request->household(), member: $request->actorOrFail());
        return response()->json(JsonUtf8Sanitizer::sanitize(['message' => 'Vous avez quitté ce foyer.', 'left_household_id' => (int) $request->household()->id, 'user' => $user]));
    }

    public function dashboard(ShowHouseholdDashboardRequest $request)
    {
        if (!$request->actor() instanceof User) { return response()->json(['message' => 'Non authentifié.'], 401); }
        if (!$request->household()) { return response()->json(['message' => 'Aucun foyer', 'requires_setup' => true]); }
        return response()->json($this->getHouseholdDashboardAction->execute($request->household()));
    }

    public function config(ShowHouseholdConfigRequest $request)
    {
        return response()->json(HouseholdConfigResource::make($this->getHouseholdConfigAction->execute($request->household())));
    }

    public function dietaryTags(ListDietaryTagsRequest $request)
    {
        return response()->json(DietaryTagResource::collection($this->getDietaryTagsAction->execute($request->household(), $request->searchTerm(), $request->normalizedType())));
    }

    public function createDietaryTag(CreateDietaryTagRequest $request)
    {
        $result = $this->createDietaryTagAction->execute($request->household(), $request->validated());
        $payload = ['message' => (string) $result['message'], 'created' => (bool) $result['created']];
        if ($result['tag'] instanceof DietaryTag) { $payload['tag'] = DietaryTagResource::make($result['tag'])->resolve($request); }
        if (is_array($result['closest_match'])) { $payload['closest_tag'] = DietaryTagResource::closestMatchPayload($result['closest_match']); }
        return response()->json(JsonUtf8Sanitizer::sanitize($payload), (int) $result['status']);
    }

    public function updateConfig(UpdateHouseholdConfigRequest $request)
    {
        $result = $this->householdManagerService->updateConfiguration(household: $request->household(), user: $request->actorOrFail(), validated: $request->validated());
        return response()->json(JsonUtf8Sanitizer::sanitize($result['payload']), (int) $result['status']);
    }
}
