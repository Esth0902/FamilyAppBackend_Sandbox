<?php

namespace App\Http\Requests\Calendar;

use App\Support\Normalization;
use Carbon\Carbon;

class CalendarBoardRequest extends CalendarContextRequest
{
    private const DEFAULT_RANGE_DAYS = 42;
    private const MAX_RANGE_DAYS = 45;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public function range(): array
    {
        return Normalization::dateRange($this, self::DEFAULT_RANGE_DAYS, self::MAX_RANGE_DAYS);
    }
}
