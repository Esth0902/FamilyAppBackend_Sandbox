<?php

namespace App\Http\Requests\Household;

class ListDietaryTagsRequest extends ShowHouseholdConfigRequest
{
    /**
     * @var array<int, string>
     */
    private const DIETARY_TAG_TYPES = ['diet', 'allergen', 'dislike', 'restriction', 'cuisine_rule'];

    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'q' => 'sometimes|string|max:100',
            'type' => 'sometimes|string|in:' . implode(',', self::DIETARY_TAG_TYPES),
        ];
    }

    public function searchTerm(): string
    {
        return trim((string) $this->query('q', ''));
    }

    public function normalizedType(): ?string
    {
        $type = trim((string) $this->query('type', ''));
        return in_array($type, self::DIETARY_TAG_TYPES, true) ? $type : null;
    }
}

