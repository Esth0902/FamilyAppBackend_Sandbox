<?php

namespace App\Services;

use App\Models\MealPoll;
use App\Models\MealSetting;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Collection;

class PollNotificationScheduler
{
    public function __construct(private readonly PollNotificationService $notificationService)
    {
    }

    public function run(): void
    {
        $this->dispatchWeeklyOpenPrompts();
        $this->processOpenPolls();
        $this->processClosedPolls();
    }

    private function dispatchWeeklyOpenPrompts(): void
    {
        $now = now();
        $currentDay = (int)$now->dayOfWeekIso;
        $windowStart = $now->copy()->subMinutes(5);

        MealSetting::query()
            ->where('enable_polls', true)
            ->where('poll_day', $currentDay)
            ->with('household.users')
            ->chunkById(100, function ($settings) use ($now, $windowStart): void {
                foreach ($settings as $setting) {
                    $pollTime = $this->safeTimeString((string)($setting->poll_time ?? '10:00'));
                    if (!$pollTime) {
                        continue;
                    }

                    [$hour, $minute] = explode(':', $pollTime);
                    $target = $now->copy()->setTime((int)$hour, (int)$minute);
                    if ($target->lt($windowStart) || $target->gt($now)) {
                        continue;
                    }

                    $householdId = (int)$setting->household_id;
                    $hasOpenPoll = MealPoll::query()
                        ->where('household_id', $householdId)
                        ->where('status', 'open')
                        ->where('ends_at', '>', $now)
                        ->exists();
                    if ($hasOpenPoll) {
                        continue;
                    }

                    $parentId = $setting->household?->users
                        ?->first(fn($user) => ($user->pivot->role ?? null) === User::ROLE_PARENT)
                        ?->id;
                    if (!$parentId) {
                        continue;
                    }

                    $alreadySentToday = UserNotification::query()
                        ->where('household_id', $householdId)
                        ->where('user_id', (int)$parentId)
                        ->where('type', 'poll_open_prompt')
                        ->whereDate('created_at', $now->toDateString())
                        ->exists();
                    if ($alreadySentToday) {
                        continue;
                    }

                    $this->notificationService->notifyParentOpenPrompt(
                        $householdId,
                        (int)$parentId,
                        (int)$setting->poll_day,
                        $pollTime
                    );
                }
            });
    }

    private function processOpenPolls(): void
    {
        $now = now();

        MealPoll::query()
            ->where('status', 'open')
            ->whereNotNull('ends_at')
            ->with('household.users')
            ->chunkById(100, function ($polls) use ($now): void {
                foreach ($polls as $poll) {
                    if ($poll->ends_at && $now->greaterThanOrEqualTo($poll->ends_at)) {
                        $poll->update([
                            'status' => 'closed',
                            'closed_at' => $poll->closed_at ?? $now,
                        ]);
                        continue;
                    }

                    if (is_null($poll->closing_soon_sent_at) && $poll->ends_at && $now->greaterThanOrEqualTo($poll->ends_at->copy()->subHours(2))) {
                        $this->notificationService->notifyPollClosingSoon($poll);
                        $poll->update(['closing_soon_sent_at' => $now]);
                    }

                    if (is_null($poll->reminder_sent_at) && $poll->starts_at && $poll->ends_at) {
                        $totalMinutes = max(60, (int)$poll->starts_at->diffInMinutes($poll->ends_at));
                        $reminderAt = $poll->starts_at->copy()->addMinutes((int)floor($totalMinutes / 2));
                        if ($now->greaterThanOrEqualTo($reminderAt)) {
                            $nonVoters = $this->resolveNonVoterUserIds($poll);
                            if ($nonVoters->isNotEmpty()) {
                                $this->notificationService->notifyPollReminder($poll, $nonVoters);
                            }
                            $poll->update(['reminder_sent_at' => $now]);
                        }
                    }
                }
            });
    }

    private function processClosedPolls(): void
    {
        $now = now();

        MealPoll::query()
            ->where('status', 'closed')
            ->whereNull('validated_at')
            ->whereNull('close_request_sent_at')
            ->with('household.users')
            ->chunkById(100, function ($polls) use ($now): void {
                foreach ($polls as $poll) {
                    $this->notificationService->notifyPollNeedsValidation($poll);
                    $poll->update(['close_request_sent_at' => $now]);
                }
            });
    }

    private function resolveNonVoterUserIds(MealPoll $poll): Collection
    {
        $allUserIds = $poll->household->users->pluck('id')->map(fn($id) => (int)$id);
        $voterIds = $poll->votes()->pluck('user_id')->map(fn($id) => (int)$id)->unique();

        return $allUserIds->diff($voterIds)->values();
    }

    private function safeTimeString(string $value): ?string
    {
        $match = preg_match('/^(\\d{1,2}):(\\d{2})(?::\\d{2})?$/', trim($value), $parts);
        if (!$match) {
            return null;
        }

        $hour = (int)$parts[1];
        $minute = (int)$parts[2];
        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }
}
