<?php

namespace App\Services;

use App\Domain\Budget\ValueObjects\BudgetComment;
use App\Models\BudgetSetting;
use App\Models\Household;
use App\Models\PocketMoneyTransaction;
use App\Models\User;
use App\Models\UserNotification;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class BudgetNotificationScheduler
{
    public function __construct(
        private readonly RealtimePublisher $realtimePublisher,
    ) {
    }

    public function run(): void
    {
        $now = now();
        $windowStart = $now->copy()->subMinutes(5);

        BudgetSetting::query()
            ->with(['household.settings', 'user:id,name'])
            ->chunkById(100, function (Collection $settings) use ($now, $windowStart): void {
                foreach ($settings as $setting) {
                    if (!$setting instanceof BudgetSetting) {
                        continue;
                    }

                    $household = $setting->household;
                    if (!$household instanceof Household) {
                        continue;
                    }

                    if (!(bool) ($household->settings?->has_budget ?? false)) {
                        continue;
                    }

                    $paymentTime = $this->resolvePaymentTime($household);
                    if ($paymentTime !== null) {
                        $resetAt = $this->resolveDueDateTime($setting, $now, $paymentTime);
                        if ($this->isWithinWindow($resetAt, $windowStart, $now)) {
                            [$remainingRaw, $periodStart, $periodEnd] = $this->computeCurrentPeriodRemainingRaw($setting, $resetAt);
                            if ($remainingRaw < -0.01) {
                                $this->dispatchDueNotification(
                                    household: $household,
                                    setting: $setting,
                                    type: 'budget_negative_due',
                                    title: 'Report budget à confirmer',
                                    bodyTemplate: '%s : solde négatif de %0.2f € à reporter au prochain budget.',
                                    amount: abs($remainingRaw),
                                    remainingRaw: $remainingRaw,
                                    periodStart: $periodStart,
                                    periodEnd: $periodEnd,
                                );
                            }
                        }
                    }

                    $paymentReminderAt = $this->resolvePaymentReminderDateTime($setting, $now);
                    if (!$this->isWithinWindow($paymentReminderAt, $windowStart, $now)) {
                        continue;
                    }

                    [$remainingRaw, $periodStart, $periodEnd] = $this->computeCurrentPeriodRemainingRaw($setting, $paymentReminderAt);
                    if ($remainingRaw <= 0.01) {
                        continue;
                    }

                    $this->dispatchDueNotification(
                        household: $household,
                        setting: $setting,
                        type: 'budget_payment_due',
                        title: 'Paiement budget à valider',
                        bodyTemplate: '%s : %0.2f € à valider pour la période %s → %s.',
                        amount: abs($remainingRaw),
                        remainingRaw: $remainingRaw,
                        periodStart: $periodStart,
                        periodEnd: $periodEnd,
                    );
                }
            });
    }

    private function isWithinWindow(?Carbon $candidate, Carbon $windowStart, Carbon $now): bool
    {
        return $candidate instanceof Carbon
            && !$candidate->lt($windowStart)
            && !$candidate->gt($now);
    }

    private function resolvePaymentReminderDateTime(BudgetSetting $setting, Carbon $now): ?Carbon
    {
        $recurrence = (string) ($setting->recurrence ?? 'weekly');
        $resetDay = (int) $setting->reset_day;

        if ($recurrence === 'monthly') {
            $safeDay = max(1, min(31, $resetDay));
            $effectiveResetDay = min($safeDay, (int) $now->daysInMonth);
            $reminderDay = $effectiveResetDay === 1
                ? (int) $now->daysInMonth
                : ($effectiveResetDay - 1);

            if ((int) $now->day !== $reminderDay) {
                return null;
            }

            return $now->copy()->setTime(9, 0, 0);
        }

        $safeWeekDay = max(1, min(7, $resetDay));
        $reminderWeekDay = $safeWeekDay === 1 ? 7 : ($safeWeekDay - 1);
        if ((int) $now->dayOfWeekIso !== $reminderWeekDay) {
            return null;
        }

        return $now->copy()->setTime(9, 0, 0);
    }

    private function dispatchDueNotification(
        Household $household,
        BudgetSetting $setting,
        string $type,
        string $title,
        string $bodyTemplate,
        float $amount,
        float $remainingRaw,
        Carbon $periodStart,
        Carbon $periodEnd,
    ): void {
        $parentIds = $this->resolveParentUserIds($household);
        if ($parentIds->isEmpty()) {
            return;
        }

        $childName = (string) ($setting->user?->name ?? 'Un enfant');
        $body = $type === 'budget_payment_due'
            ? sprintf(
                $bodyTemplate,
                $childName,
                $amount,
                $periodStart->toDateString(),
                $periodEnd->toDateString()
            )
            : sprintf(
                $bodyTemplate,
                $childName,
                $amount
            );

        foreach ($parentIds as $parentId) {
            $alreadySent = UserNotification::query()
                ->where('household_id', (int) $household->id)
                ->where('user_id', (int) $parentId)
                ->where('type', $type)
                ->where('data->child_user_id', (int) $setting->user_id)
                ->where('data->period_start', $periodStart->toDateString())
                ->where('data->period_end', $periodEnd->toDateString())
                ->exists();

            if ($alreadySent) {
                continue;
            }

            $notification = UserNotification::query()->create([
                'household_id' => (int) $household->id,
                'user_id' => (int) $parentId,
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'data' => [
                    'household_id' => (int) $household->id,
                    'child_user_id' => (int) $setting->user_id,
                    'child_name' => $childName,
                    'amount' => $amount,
                    'remaining_raw' => $remainingRaw,
                    'period_start' => $periodStart->toDateString(),
                    'period_end' => $periodEnd->toDateString(),
                ],
            ]);

            $this->realtimePublisher->publishUser(
                userId: (int) $parentId,
                module: 'notifications',
                type: 'notification_created',
                payload: [
                    'notification_id' => (int) $notification->id,
                    'household_id' => (int) $household->id,
                    'type' => $type,
                    'title' => $title,
                    'body' => $body,
                ],
            );
        }
    }

    private function resolveDueDateTime(BudgetSetting $setting, Carbon $now, string $paymentTime): ?Carbon
    {
        [$hour, $minute] = explode(':', $paymentTime);
        $recurrence = (string) ($setting->recurrence ?? 'weekly');
        $resetDay = (int) $setting->reset_day;

        if ($recurrence === 'monthly') {
            $safeDay = max(1, min(31, $resetDay));
            $dayOfMonth = min($safeDay, (int) $now->daysInMonth);
            if ((int) $now->day !== $dayOfMonth) {
                return null;
            }

            return $now->copy()->setTime((int) $hour, (int) $minute, 0);
        }

        $safeWeekDay = max(1, min(7, $resetDay));
        if ((int) $now->dayOfWeekIso !== $safeWeekDay) {
            return null;
        }

        return $now->copy()->setTime((int) $hour, (int) $minute, 0);
    }

    /**
     * @return array{0: float, 1: Carbon, 2: Carbon}
     */
    private function computeCurrentPeriodRemainingRaw(BudgetSetting $setting, Carbon $anchor): array
    {
        $recurrence = (string) ($setting->recurrence ?? 'weekly');
        $resetDay = (int) $setting->reset_day;
        [$periodStart, $periodEnd] = $this->resolvePeriodBoundaries($recurrence, $resetDay, $anchor);

        $transactions = PocketMoneyTransaction::query()
            ->where('household_id', (int) $setting->household_id)
            ->where('user_id', (int) $setting->user_id)
            ->where('created_at', '>=', $periodStart)
            ->where('created_at', '<=', $periodEnd)
            ->get();

        $approved = $transactions->where('status', 'approved')->values();
        $baseAmount = (float) ($setting->base_amount ?? 0);
        $bonusTotal = $approved
            ->where('type', 'bonus')
            ->sum(static fn(PocketMoneyTransaction $tx): float => abs((float) $tx->amount));
        $penaltyTotal = $approved
            ->where('type', 'penalty')
            ->sum(static fn(PocketMoneyTransaction $tx): float => -abs((float) $tx->amount));
        $advanceToDeduct = $approved
            ->where('type', 'advance')
            ->filter(fn(PocketMoneyTransaction $tx): bool => $this->resolveAdvanceRequestKind($tx) === 'advance')
            ->sum(static fn(PocketMoneyTransaction $tx): float => abs((float) $tx->amount));
        $alreadyPaid = $approved
            ->where('type', 'allocation')
            ->sum(static fn(PocketMoneyTransaction $tx): float => abs((float) $tx->amount));

        $totalExpected = $baseAmount + $bonusTotal + $penaltyTotal - $advanceToDeduct;
        $remainingRaw = $totalExpected - $alreadyPaid;

        return [$remainingRaw, $periodStart, $periodEnd];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolvePeriodBoundaries(string $recurrence, int $resetDay, Carbon $now): array
    {
        $today = $now->copy()->startOfDay();

        if ($recurrence === 'monthly') {
            $currentMonthStart = $today->copy()->startOfMonth();
            $periodStart = $this->resolveMonthlyResetDate($currentMonthStart, $resetDay);

            if ($today->lt($periodStart)) {
                $periodStart = $this->resolveMonthlyResetDate(
                    $currentMonthStart->copy()->subMonthNoOverflow()->startOfMonth(),
                    $resetDay
                );
            }

            $nextMonth = $periodStart->copy()->addMonthNoOverflow()->startOfMonth();
            $nextPeriodStart = $this->resolveMonthlyResetDate($nextMonth, $resetDay);
            $periodEnd = $nextPeriodStart->copy()->subSecond();

            return [$periodStart, $periodEnd];
        }

        $safeResetDay = max(1, min(7, $resetDay));
        $delta = ((int) $today->isoWeekday() - $safeResetDay + 7) % 7;
        $periodStart = $today->copy()->subDays($delta);
        $periodEnd = $periodStart->copy()->addDays(7)->subSecond();

        return [$periodStart, $periodEnd];
    }

    private function resolveMonthlyResetDate(Carbon $monthStart, int $resetDay): Carbon
    {
        $safeResetDay = max(1, min(31, $resetDay));
        $day = min($safeResetDay, (int) $monthStart->daysInMonth);
        return $monthStart->copy()->day($day)->startOfDay();
    }

    private function resolveAdvanceRequestKind(PocketMoneyTransaction $transaction): string
    {
        $comment = $transaction->comment instanceof BudgetComment
            ? $transaction->comment
            : BudgetComment::fromStored((string) $transaction->comment);

        return $comment->requestKind;
    }

    private function resolvePaymentTime(Household $household): ?string
    {
        $config = is_array($household->settings?->budget_config) ? $household->settings->budget_config : [];
        $raw = is_string($config['payment_time'] ?? null) ? trim((string) $config['payment_time']) : '09:00';
        $match = preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $raw, $parts);
        if (!$match) {
            return null;
        }

        $hour = (int) $parts[1];
        $minute = (int) $parts[2];
        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    /**
     * @return Collection<int, int>
     */
    private function resolveParentUserIds(Household $household): Collection
    {
        return $household->users()
            ->wherePivot('role', User::ROLE_PARENT)
            ->pluck('users.id')
            ->map(static fn(mixed $id): int => (int) $id)
            ->filter(static fn(int $id): bool => $id > 0)
            ->values();
    }
}
