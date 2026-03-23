<?php

namespace App\Services;

use App\Models\MealPoll;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Collection;

class PollNotificationService
{
    /**
     * @param  array<string, mixed>|null  $data
     */
    public function notifyUsers(
        int $householdId,
        Collection $userIds,
        string $type,
        string $title,
        string $body,
        ?array $data = null,
        $scheduledFor = null
    ): void {
        $uniqueIds = $userIds
            ->map(fn($id) => (int)$id)
            ->filter(fn($id) => $id > 0)
            ->unique()
            ->values();

        if ($uniqueIds->isEmpty()) {
            return;
        }

        $now = now();
        $rows = $uniqueIds->map(function (int $userId) use ($householdId, $type, $title, $body, $data, $scheduledFor, $now): array {
            return [
                'household_id' => $householdId,
                'user_id' => $userId,
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'data' => $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null,
                'scheduled_for' => $scheduledFor,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        })->all();

        UserNotification::insert($rows);
    }

    public function notifyPollOpened(MealPoll $poll): void
    {
        $userIds = $poll->household->users()->pluck('users.id');
        $this->notifyUsers(
            (int)$poll->household_id,
            $userIds,
            'poll_opened',
            'Nouveau sondage repas',
            'Un nouveau sondage a été ouvert, viens voter !',
            [
                'poll_id' => $poll->id,
            ]
        );
    }

    public function notifyPollReminder(MealPoll $poll, Collection $userIds): void
    {
        $this->notifyUsers(
            (int)$poll->household_id,
            $userIds,
            'poll_reminder',
            'Rappel vote sondage',
            'Le sondage repas est toujours ouvert, viens vite voter !',
            [
                'poll_id' => $poll->id,
                'ends_at' => optional($poll->ends_at)->toIso8601String(),
            ]
        );
    }

    public function notifyPollClosingSoon(MealPoll $poll): void
    {
        $userIds = $poll->household->users()->pluck('users.id');
        $this->notifyUsers(
            (int)$poll->household_id,
            $userIds,
            'poll_closing_soon',
            'Sondage bientôt clôturé',
            'Le sondage repas se termine bientôt, viens vite voter !',
            [
                'poll_id' => $poll->id,
                'ends_at' => optional($poll->ends_at)->toIso8601String(),
            ]
        );
    }

    public function notifyPollNeedsValidation(MealPoll $poll): void
    {
        $parentId = $poll->household->users()
            ->wherePivot('role', User::ROLE_PARENT)
            ->orderBy('users.id')
            ->value('users.id');

        if (!$parentId) {
            return;
        }

        $this->notifyUsers(
            (int)$poll->household_id,
            collect([(int)$parentId]),
            'poll_needs_validation',
            'Clôture du sondage',
            'Le sondage est terminé, les plats gagnants doivent être validés.',
            [
                'poll_id' => $poll->id,
            ]
        );
    }

    public function notifyPollValidated(MealPoll $poll): void
    {
        $userIds = $poll->household->users()->pluck('users.id');
        $this->notifyUsers(
            (int)$poll->household_id,
            $userIds,
            'poll_validated',
            'Résultats du sondage',
            'Les plats gagnants ont été validés.',
            [
                'poll_id' => $poll->id,
            ]
        );
    }

    public function notifyPollWinner(MealPoll $poll, ?int $winnerRecipeId): void
    {
        if (!$winnerRecipeId) {
            return;
        }

        $poll->loadMissing(['household.users', 'options.recipe']);
        $winnerTitle = (string) (
            $poll->options
                ->firstWhere('recipe_id', $winnerRecipeId)
                ?->recipe
                ?->title
            ?? 'Recette gagnante'
        );

        $userIds = $poll->household->users()->pluck('users.id');
        $this->notifyUsers(
            (int) $poll->household_id,
            $userIds,
            'poll_winner_announced',
            'Repas gagnant',
            "Le repas gagnant est : {$winnerTitle}.",
            [
                'poll_id' => (int) $poll->id,
                'winner_recipe_id' => (int) $winnerRecipeId,
                'winner_recipe_title' => $winnerTitle,
            ]
        );
    }

    public function notifyPollClosedTooLate(MealPoll $poll): void
    {
        $poll->loadMissing('household.users');
        $nonVoters = $this->resolveNonVoterUserIds($poll);

        if ($nonVoters->isEmpty()) {
            return;
        }

        $this->notifyUsers(
            (int)$poll->household_id,
            $nonVoters,
            'poll_closed_too_late',
            'Sondage clôturé',
            'Le sondage est clôturé, il est trop tard pour voter. Consulte les résultats.',
            [
                'poll_id' => (int) $poll->id,
                'ends_at' => optional($poll->ends_at)->toIso8601String(),
                'closed_at' => optional($poll->closed_at)->toIso8601String(),
            ]
        );
    }

    public function notifyParentOpenPrompt(int $householdId, int $parentUserId, int $pollDay, string $pollTime): void
    {
        $this->notifyUsers(
            $householdId,
            collect([$parentUserId]),
            'poll_open_prompt',
            'Ouvrir le sondage hebdomadaire',
            'C\'est le moment d\'ouvrir le sondage de la semaine.',
            [
                'poll_day' => $pollDay,
                'poll_time' => $pollTime,
            ]
        );
    }

    private function resolveNonVoterUserIds(MealPoll $poll): Collection
    {
        $allUserIds = $poll->household?->users
            ?->pluck('id')
            ->map(static fn($id): int => (int) $id)
            ->filter(static fn(int $id): bool => $id > 0)
            ->values()
            ?? collect();

        if ($allUserIds->isEmpty()) {
            return collect();
        }

        $voterIds = $poll->votes()
            ->pluck('user_id')
            ->map(static fn($id): int => (int) $id)
            ->filter(static fn(int $id): bool => $id > 0)
            ->unique()
            ->values();

        return $allUserIds->diff($voterIds)->values();
    }
}
