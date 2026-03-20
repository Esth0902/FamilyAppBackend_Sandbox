<?php

namespace App\Http\Resources\Calendar;

use App\Models\MealPlanAttendance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/** @mixin MealPlanAttendance */
class MealPlanAttendanceResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(
        mixed $resource,
        private readonly ?string $message = null,
        private readonly bool $includeResponseWrapper = false,
    ) {
        parent::__construct($resource);
    }

    public static function mutation(MealPlanAttendance $attendance, string $message): self
    {
        return new self($attendance, $message, true);
    }

    /**
     * @param Collection<int, MealPlanAttendance> $attendances
     * @param array<int, array{id:int,name:string}> $householdMembers
     * @return array{
     *   present: array<int, array{id:int,name:string,reason:?string,responded_at:?string}>,
     *   not_home: array<int, array{id:int,name:string,reason:?string,responded_at:?string}>,
     *   later: array<int, array{id:int,name:string,reason:?string,responded_at:?string}>,
     *   unanswered: array<int, array{id:int,name:string}>
     * }
     */
    public static function overview(Collection $attendances, array $householdMembers): array
    {
        $membersById = collect($householdMembers)
            ->mapWithKeys(static fn (array $member): array => [(int) ($member['id'] ?? 0) => [
                'id' => (int) ($member['id'] ?? 0),
                'name' => (string) ($member['name'] ?? 'Membre'),
            ]])
            ->filter(static fn (array $member, int $id): bool => $id > 0)
            ->all();

        $present = [];
        $notHome = [];
        $later = [];
        $respondedIds = [];

        foreach ($attendances as $attendance) {
            $userId = (int) $attendance->user_id;
            if ($userId <= 0 || !array_key_exists($userId, $membersById)) {
                continue;
            }

            $respondedIds[$userId] = true;
            $payload = [
                'id' => $userId,
                'name' => $membersById[$userId]['name'],
                'reason' => $attendance->reason,
                'responded_at' => optional($attendance->responded_at)->toIso8601String(),
            ];

            $status = (string) $attendance->status;
            if ($status === 'present') {
                $present[] = $payload;
                continue;
            }
            if ($status === 'later') {
                $later[] = $payload;
                continue;
            }

            $notHome[] = $payload;
        }

        $unanswered = collect($membersById)
            ->reject(static fn (array $member): bool => isset($respondedIds[(int) $member['id']]))
            ->values()
            ->all();

        return [
            'present' => array_values($present),
            'not_home' => array_values($notHome),
            'later' => array_values($later),
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
                'attendance' => $payload,
            ];
        }

        return $payload;
    }
}
