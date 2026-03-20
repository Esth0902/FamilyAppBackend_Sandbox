<?php

namespace App\Http\Resources\Calendar;

use App\Models\EventParticipation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/** @mixin EventParticipation */
class EventParticipationResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(
        mixed $resource,
        private readonly ?string $message = null,
        private readonly bool $includeResponseWrapper = false,
    ) {
        parent::__construct($resource);
    }

    public static function mutation(EventParticipation $participation, string $message): self
    {
        return new self($participation, $message, true);
    }

    /**
     * @param Collection<int, EventParticipation> $participations
     * @param array<int, array{id:int,name:string}> $householdMembers
     * @return array{
     *   participate: array<int, array{id:int,name:string,reason:?string,responded_at:?string}>,
     *   not_participate: array<int, array{id:int,name:string,reason:?string,responded_at:?string}>,
     *   unanswered: array<int, array{id:int,name:string}>
     * }
     */
    public static function overview(Collection $participations, array $householdMembers): array
    {
        $membersById = collect($householdMembers)
            ->mapWithKeys(static fn (array $member): array => [(int) ($member['id'] ?? 0) => [
                'id' => (int) ($member['id'] ?? 0),
                'name' => (string) ($member['name'] ?? 'Membre'),
            ]])
            ->filter(static fn (array $member, int $id): bool => $id > 0)
            ->all();

        $participate = [];
        $notParticipate = [];
        $respondedIds = [];

        foreach ($participations as $participation) {
            $userId = (int) $participation->user_id;
            if ($userId <= 0 || !array_key_exists($userId, $membersById)) {
                continue;
            }

            $respondedIds[$userId] = true;
            $payload = [
                'id' => $userId,
                'name' => $membersById[$userId]['name'],
                'reason' => $participation->reason,
                'responded_at' => optional($participation->responded_at)->toIso8601String(),
            ];

            if ((string) $participation->status === 'participate') {
                $participate[] = $payload;
                continue;
            }

            $notParticipate[] = $payload;
        }

        $unanswered = collect($membersById)
            ->reject(static fn (array $member): bool => isset($respondedIds[(int) $member['id']]))
            ->values()
            ->all();

        return [
            'participate' => array_values($participate),
            'not_participate' => array_values($notParticipate),
            'unanswered' => $unanswered,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = [
            'status' => (string) $this->status,
            'reason' => $this->reason,
            'responded_at' => optional($this->responded_at)->toIso8601String(),
        ];

        if ($this->includeResponseWrapper) {
            return [
                'message' => (string) $this->message,
                'participation' => $payload,
            ];
        }

        return $payload;
    }
}
