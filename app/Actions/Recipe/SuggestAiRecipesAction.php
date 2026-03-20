<?php

namespace App\Actions\Recipe;

use App\Services\AiService;

class SuggestAiRecipesAction
{
    public function __construct(private readonly AiService $aiService)
    {
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function execute(array $validated): array
    {
        $text = (string) ($validated['preferences'] ?? '');
        $dietaryPreferences = trim((string) ($validated['dietary_preferences'] ?? ''));
        $intent = (string) ($validated['intent'] ?? 'ideas');
        $count = (int) ($validated['count'] ?? 3);

        if ($intent === 'specific') {
            $recipe = $this->aiService->getFullRecipeDetails($text, $dietaryPreferences);
            if (empty($recipe)) {
                return [
                    'status' => 422,
                    'payload' => ['message' => 'Impossible de générer la recette'],
                ];
            }

            return [
                'status' => 200,
                'payload' => ['type' => 'single', 'data' => $recipe],
            ];
        }

        $ideasPrompt = trim($text);
        if ($dietaryPreferences !== '') {
            $ideasPrompt = $ideasPrompt === ''
                ? $dietaryPreferences
                : $ideasPrompt . "\n" . $dietaryPreferences;
        }

        $ideas = $this->aiService->suggestMealIdeas($count, $ideasPrompt);

        return [
            'status' => 200,
            'payload' => ['type' => 'list', 'data' => $ideas],
        ];
    }
}
