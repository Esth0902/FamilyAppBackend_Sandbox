<?php

namespace App\Actions\Recipe;

use App\Services\AiService;

class PreviewAiRecipeAction
{
    public function __construct(private readonly AiService $aiService)
    {
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function execute(array $validated): array
    {
        return $this->aiService->getFullRecipeDetails(
            (string) $validated['title'],
            trim((string) ($validated['dietary_preferences'] ?? ''))
        );
    }
}
