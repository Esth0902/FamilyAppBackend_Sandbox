<?php

namespace App\Http\Requests\MealPoll;

use App\Models\MealPoll;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;

class CloseMealPollRequest extends MealPollContextRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $poll = $this->pollFromRoute();
        if (!$user instanceof User || !$poll instanceof MealPoll) {
            return false;
        }

        $household = $this->household();
        $this->ensurePollBelongsToHousehold($poll, $household);

        return $user->can('close', $poll);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $poll = $this->pollFromRoute();
            if (!$poll instanceof MealPoll || $validator->errors()->isNotEmpty()) {
                return;
            }

            if ((string) $poll->status === 'validated') {
                $validator->errors()->add('poll', 'Ce sondage est deja valide.');
            }
        });
    }
}
