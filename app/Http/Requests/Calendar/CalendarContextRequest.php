<?php

namespace App\Http\Requests\Calendar;

use App\Http\Controllers\Api\Concerns\InteractsWithCalendarContext;
use App\Http\Controllers\Api\Concerns\ResolvesHouseholdContext;
use App\Models\Household;
use Illuminate\Foundation\Http\FormRequest;

abstract class CalendarContextRequest extends FormRequest
{
    use InteractsWithCalendarContext;
    use ResolvesHouseholdContext;

    private ?Household $resolvedHousehold = null;

    private ?string $resolvedHouseholdRole = null;

    public function household(): Household
    {
        $this->resolveContext();

        return $this->resolvedHousehold;
    }

    public function householdRole(): string
    {
        $this->resolveContext();

        return (string) $this->resolvedHouseholdRole;
    }

    private function resolveContext(): void
    {
        if ($this->resolvedHousehold instanceof Household && is_string($this->resolvedHouseholdRole)) {
            return;
        }

        [$household, $role] = $this->resolveHouseholdWithRole($this);

        $this->resolvedHousehold = $household;
        $this->resolvedHouseholdRole = $role;
    }
}
