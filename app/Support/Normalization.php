<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class Normalization
{
    /**
     * @return array<int, int>
     */
    public static function memberIds(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $candidate) {
            $id = (int) $candidate;
            if ($id <= 0) {
                continue;
            }

            if (!in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    public static function isoWeekDay(mixed $value, int $default = 1): int
    {
        $safeDefault = max(1, min(7, $default));
        $parsed = (int) $value;

        if ($parsed < 1 || $parsed > 7) {
            return $safeDefault;
        }

        return $parsed;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function dateRange(Request $request, int $defaultRangeDays = 14, int $maxRangeDays = 45): array
    {
        $safeDefaultRangeDays = max(1, $defaultRangeDays);
        $safeMaxRangeDays = max($safeDefaultRangeDays, $maxRangeDays);

        $fromInput = (string) ($request->query('from') ?? now()->toDateString());
        $toInput = (string) ($request->query('to') ?? now()->copy()->addDays($safeDefaultRangeDays - 1)->toDateString());

        try {
            $fromDate = Carbon::createFromFormat('Y-m-d', $fromInput);
            $toDate = Carbon::createFromFormat('Y-m-d', $toInput);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'dates' => ['Renseignez une date de début et de fin valides (YYYY-MM-DD).'],
            ]);
        }

        if (!$fromDate || $fromDate->toDateString() !== $fromInput || !$toDate || $toDate->toDateString() !== $toInput) {
            throw ValidationException::withMessages([
                'dates' => ['Renseignez une date de début et de fin valides (YYYY-MM-DD).'],
            ]);
        }

        $fromDate = $fromDate->startOfDay();
        $toDate = $toDate->startOfDay();

        if ($toDate->lt($fromDate)) {
            throw ValidationException::withMessages([
                'dates' => ['La date de fin doit être supérieure ou égale à la date de début.'],
            ]);
        }

        $rangeDays = $fromDate->diffInDays($toDate) + 1;
        if ($rangeDays > $safeMaxRangeDays) {
            throw ValidationException::withMessages([
                'dates' => ['La période demandée est trop longue.'],
            ]);
        }

        return [$fromDate, $toDate];
    }
}
