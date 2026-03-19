<?php

namespace App\Casts;

use App\Domain\Budget\ValueObjects\BudgetComment;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Stringable;

class BudgetCommentCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): BudgetComment
    {
        return BudgetComment::fromStored(is_string($value) ? $value : null);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value instanceof BudgetComment) {
            return $value->toStoredString();
        }

        if ($value === null) {
            return null;
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        if (is_string($value)) {
            return (new BudgetComment($value))->toStoredString();
        }

        return (string) $value;
    }
}
