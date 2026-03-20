<?php

namespace App\Http\Resources\MealPoll;

use App\Models\MealPollVote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MealPollVoteResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(mixed $resource, private readonly ?int $votesCount = null)
    {
        parent::__construct($resource);
    }

    public static function summary(int $userId, string $name, int $votesCount): self
    {
        return new self([
            'user_id' => $userId,
            'name' => $name,
        ], $votesCount);
    }

    /**
     * @return array<string, int|string>
     */
    public function toArray(Request $request): array
    {
        $userId = 0;
        $name = 'Utilisateur';
        $mealPollOptionId = null;
        $votesCount = $this->votesCount;

        if ($this->resource instanceof MealPollVote) {
            $userId = (int) $this->resource->user_id;
            $name = (string) ($this->resource->user?->name ?? 'Utilisateur');
            $mealPollOptionId = $this->resource->meal_poll_option_id !== null
                ? (int) $this->resource->meal_poll_option_id
                : null;
        } else {
            $userId = (int) data_get($this->resource, 'user_id', 0);
            $name = (string) data_get($this->resource, 'name', 'Utilisateur');
            $mealPollOptionId = data_get($this->resource, 'meal_poll_option_id');
            $mealPollOptionId = is_numeric($mealPollOptionId) ? (int) $mealPollOptionId : null;
        }

        $payload = [
            'user_id' => $userId,
            'name' => $name,
        ];

        if ($mealPollOptionId !== null) {
            $payload['meal_poll_option_id'] = $mealPollOptionId;
        }

        if ($votesCount !== null) {
            $payload['votes_count'] = $votesCount;
        }

        return $payload;
    }
}
