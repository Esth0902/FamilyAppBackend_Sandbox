<?php

namespace App\Http\Resources\Calendar;

use App\Models\Event;
use App\Models\EventInvitation;
use App\Models\EventParticipation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Event */
class EventResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(
        mixed $resource,
        private readonly int $currentUserId = 0,
        private readonly string $role = User::ROLE_CHILD,
        private readonly int $currentHouseholdId = 0,
        private readonly array $householdMembers = [],
        private readonly ?string $message = null,
        private readonly bool $includeResponseWrapper = false,
    ) {
        parent::__construct($resource);
    }

    public static function forBoard(
        Event $event,
        int $currentUserId,
        string $role,
        int $currentHouseholdId,
        array $householdMembers
    ): self {
        return new self($event, $currentUserId, $role, $currentHouseholdId, $householdMembers);
    }

    public static function mutation(
        Event $event,
        int $currentUserId,
        string $role,
        int $currentHouseholdId,
        array $householdMembers,
        string $message
    ): self {
        return new self(
            resource: $event,
            currentUserId: $currentUserId,
            role: $role,
            currentHouseholdId: $currentHouseholdId,
            householdMembers: $householdMembers,
            message: $message,
            includeResponseWrapper: true,
        );
    }

    public static function deleted(string $message): self
    {
        return new self(resource: null, message: $message, includeResponseWrapper: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if (!$this->resource instanceof Event) {
            return [
                'message' => (string) $this->message,
            ];
        }

        $belongsToCurrentHousehold = (int) $this->household_id === $this->currentHouseholdId;
        $canManage = $belongsToCurrentHousehold
            && (
                $this->role === User::ROLE_PARENT
                || (int) $this->created_by_user_id === $this->currentUserId
            );

        $audienceMode = Event::normalizeAudienceMode((string) $this->audience_mode);
        $responseRequired = (bool) ($this->response_required ?? true);

        $audienceUserIds = $this->resolveAudienceUserIds($audienceMode);
        $isInvited = $belongsToCurrentHousehold
            && (
                $audienceMode === Event::AUDIENCE_ALL_MEMBERS
                || in_array($this->currentUserId, $audienceUserIds, true)
            );

        $participations = $this->relationLoaded('participations')
            ? $this->participations
            : collect();
        $visibleParticipations = $participations->filter(
            static fn (EventParticipation $participation): bool => in_array((int) $participation->user_id, $audienceUserIds, true)
        );
        $myParticipation = $visibleParticipations->first(
            fn (EventParticipation $participation): bool => (int) $participation->user_id === $this->currentUserId
        );
        if (!$responseRequired || !$isInvited) {
            $myParticipation = null;
        }

        $invitedMembers = collect($this->householdMembers)
            ->filter(static fn (array $member): bool => in_array((int) ($member['id'] ?? 0), $audienceUserIds, true))
            ->values()
            ->all();

        $payload = [
            'id' => (int) $this->id,
            'title' => (string) $this->title,
            'description' => $this->description,
            'start_at' => optional($this->start_at)->toIso8601String(),
            'end_at' => optional($this->end_at)->toIso8601String(),
            'is_shared_with_other_household' => (bool) $this->is_shared_with_other_household,
            'source_household_id' => (int) $this->household_id,
            'audience_mode' => $audienceMode,
            'response_required' => $responseRequired,
            'invited_user_ids' => $belongsToCurrentHousehold ? array_values($audienceUserIds) : [],
            'created_by' => [
                'id' => $this->creator?->id ? (int) $this->creator->id : null,
                'name' => $this->creator?->name,
            ],
            'my_participation' => $myParticipation instanceof EventParticipation
                ? EventParticipationResource::make($myParticipation)->resolve($request)
                : null,
            'participation_overview' => $responseRequired
                ? EventParticipationResource::overview($visibleParticipations, $invitedMembers)
                : null,
            'invitation' => [
                'audience_mode' => $audienceMode,
                'response_required' => $responseRequired,
                'is_invited' => $isInvited,
            ],
            'permissions' => [
                'can_update' => $canManage,
                'can_delete' => $canManage,
                'can_confirm_participation' => $belongsToCurrentHousehold && $isInvited && $responseRequired,
            ],
        ];

        if ($this->includeResponseWrapper) {
            return [
                'message' => (string) $this->message,
                'event' => $payload,
            ];
        }

        return $payload;
    }

    /**
     * @return array<int, int>
     */
    private function resolveAudienceUserIds(string $audienceMode): array
    {
        if ($audienceMode === Event::AUDIENCE_ALL_MEMBERS) {
            return collect($this->householdMembers)
                ->map(static fn (array $member): int => (int) ($member['id'] ?? 0))
                ->filter(static fn (int $id): bool => $id > 0)
                ->values()
                ->all();
        }

        $invitations = $this->relationLoaded('invitations')
            ? $this->invitations
            : collect();

        return $invitations
            ->map(static fn (EventInvitation $invitation): int => (int) $invitation->user_id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();
    }
}
