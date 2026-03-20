<?php

namespace App\Http\Requests\MealPoll;

use App\Http\Controllers\Api\Concerns\ResolvesHouseholdContext;
use App\Models\Household;
use App\Models\MealPoll;
use App\Models\MealSetting;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

abstract class MealPollContextRequest extends FormRequest
{
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

    protected function pollFromRoute(): ?MealPoll
    {
        $poll = $this->route('poll');

        return $poll instanceof MealPoll ? $poll : null;
    }

    protected function ensurePollBelongsToHousehold(?MealPoll $poll, Household $household): void
    {
        if (!$poll instanceof MealPoll || (int) $poll->household_id !== (int) $household->id) {
            throw new NotFoundHttpException('Sondage introuvable.');
        }
    }

    protected function ensurePollModuleEnabled(Household $household): void
    {
        $mealSettings = MealSetting::query()
            ->where('household_id', $household->id)
            ->first();

        if ($mealSettings && !(bool) $mealSettings->enable_polls) {
            throw new AuthorizationException('Le module Sondages est desactive pour ce foyer.');
        }
    }

    protected function ensurePollIsOpenForVoting(MealPoll $poll): void
    {
        if ((string) $poll->status !== 'open' || now()->greaterThan($poll->ends_at)) {
            throw new AuthorizationException('Le sondage est cloture.');
        }
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
