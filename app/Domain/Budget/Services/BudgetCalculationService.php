<?php

namespace App\Domain\Budget\Services;

use Carbon\Carbon;

class BudgetCalculationService
{
    private const TYPE_ADVANCE = 'advance';
    private const TYPE_PENALTY = 'penalty';
    private const REQUEST_KIND_ADVANCE = 'advance';
    private const REQUEST_KIND_REIMBURSEMENT = 'reimbursement';

    public function normalizeRequestedAmount(float|int|string $amount): float
    {
        return abs((float) $amount);
    }

    public function isAdvanceAmountAllowed(float $requestedAmount, float $maxAdvanceAmount): bool
    {
        if ($maxAdvanceAmount <= 0) {
            return false;
        }

        return $requestedAmount <= $maxAdvanceAmount;
    }

    public function normalizeResetDay(int $value, string $recurrence): int
    {
        return $recurrence === 'monthly'
            ? max(1, min(31, $value))
            : max(1, min(7, $value));
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public function resolvePeriodBoundaries(string $recurrence, int $resetDay, Carbon $now): array
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

            return [$periodStart, $nextPeriodStart->copy()->subSecond()];
        }

        $safeResetDay = max(1, min(7, $resetDay));
        $delta = ((int) $today->isoWeekday() - $safeResetDay + 7) % 7;
        $periodStart = $today->copy()->subDays($delta);

        return [$periodStart, $periodStart->copy()->addDays(7)->subSecond()];
    }

    public function signedTransactionAmount(
        float $amount,
        string $type,
        string $requestKind = self::REQUEST_KIND_ADVANCE
    ): float {
        $normalizedAmount = abs($amount);
        if ($type === self::TYPE_PENALTY) {
            return -$normalizedAmount;
        }

        if ($type === self::TYPE_ADVANCE && $requestKind === self::REQUEST_KIND_REIMBURSEMENT) {
            return 0.0;
        }

        return $normalizedAmount;
    }

    public function calculateRemainingRaw(
        float $baseAmount,
        float $bonusTotal,
        float $penaltyTotal,
        float $advanceToDeduct,
        float $alreadyPaid
    ): float {
        $totalExpected = $baseAmount + $bonusTotal + $penaltyTotal - $advanceToDeduct;

        return $totalExpected - $alreadyPaid;
    }

    private function resolveMonthlyResetDate(Carbon $monthStart, int $resetDay): Carbon
    {
        $safeResetDay = max(1, min(31, $resetDay));
        $day = min($safeResetDay, (int) $monthStart->daysInMonth);

        return $monthStart->copy()->day($day)->startOfDay();
    }
}

