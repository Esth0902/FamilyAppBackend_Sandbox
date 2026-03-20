<?php

namespace App\Http\Requests\MealPoll;

use App\Models\MealPoll;
use App\Models\MealPollOption;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Collection;

class SyncMealPollVotesRequest extends MealPollContextRequest
{
    /**
     * @var Collection<int, int>|null
     */
    private ?Collection $resolvedOptionIds = null;

    public function authorize(): bool
    {
        $user = $this->user();
        $poll = $this->pollFromRoute();
        if (!$user instanceof User || !$poll instanceof MealPoll) {
            return false;
        }

        $household = $this->household();
        $this->ensurePollBelongsToHousehold($poll, $household);
        $this->ensurePollIsOpenForVoting($poll);

        return $user->can('vote', $poll);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'option_ids' => ['required', 'array', 'min:1', 'max:20'],
            'option_ids.*' => ['required', 'integer'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $poll = $this->pollFromRoute();
            if (!$poll instanceof MealPoll) {
                $validator->errors()->add('poll', 'Sondage introuvable.');
                return;
            }

            $optionIds = $this->optionIds();

            $validOptionsCount = MealPollOption::query()
                ->where('meal_poll_id', $poll->id)
                ->whereIn('id', $optionIds->all())
                ->count();

            if ($validOptionsCount !== $optionIds->count()) {
                $validator->errors()->add('option_ids', 'Une ou plusieurs options de vote sont invalides.');
                return;
            }

            $maxVotes = max(1, (int) $poll->max_votes_per_user);
            if ($optionIds->count() !== $maxVotes) {
                $validator->errors()->add('option_ids', "Vous devez choisir exactement {$maxVotes} plats pour ce sondage.");
            }
        });
    }

    /**
     * @return Collection<int, int>
     */
    public function optionIds(): Collection
    {
        if ($this->resolvedOptionIds instanceof Collection) {
            return $this->resolvedOptionIds;
        }

        $this->resolvedOptionIds = collect((array) $this->input('option_ids'))
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values();

        return $this->resolvedOptionIds;
    }
}
