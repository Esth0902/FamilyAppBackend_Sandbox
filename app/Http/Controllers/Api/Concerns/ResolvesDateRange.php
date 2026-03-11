<?php

namespace App\Http\Controllers\Api\Concerns;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

trait ResolvesDateRange
{
    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function resolveDateRange(
        Request $request,
        int $defaultRangeDays,
        int $maxRangeDays
    ): array {
        $safeDefaultRangeDays = max(1, $defaultRangeDays);
        $safeMaxRangeDays = max($safeDefaultRangeDays, $maxRangeDays);

        $fromInput = (string) ($request->query('from') ?? now()->toDateString());
        $toInput = (string) ($request->query('to') ?? now()->copy()->addDays($safeDefaultRangeDays - 1)->toDateString());

        try {
            $fromDate = Carbon::createFromFormat('Y-m-d', $fromInput);
            $toDate = Carbon::createFromFormat('Y-m-d', $toInput);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'dates' => ['Renseignez une date de debut et de fin valides (YYYY-MM-DD).'],
            ]);
        }

        if (!$fromDate || $fromDate->toDateString() !== $fromInput || !$toDate || $toDate->toDateString() !== $toInput) {
            throw ValidationException::withMessages([
                'dates' => ['Renseignez une date de debut et de fin valides (YYYY-MM-DD).'],
            ]);
        }

        $fromDate = $fromDate->startOfDay();
        $toDate = $toDate->startOfDay();

        if ($toDate->lt($fromDate)) {
            throw ValidationException::withMessages([
                'dates' => ['La date de fin doit etre superieure ou egale a la date de debut.'],
            ]);
        }

        $rangeDays = $fromDate->diffInDays($toDate) + 1;
        if ($rangeDays > $safeMaxRangeDays) {
            throw ValidationException::withMessages([
                'dates' => ['La periode demandee est trop longue.'],
            ]);
        }

        return [$fromDate, $toDate];
    }
}
