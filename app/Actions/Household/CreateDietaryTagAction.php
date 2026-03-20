<?php

namespace App\Actions\Household;

use App\Models\DietaryTag;
use App\Models\Household;
use App\Services\EmbeddingService;
use Illuminate\Support\Str;

class CreateDietaryTagAction
{
    private const DIETARY_TAG_SIMILARITY_THRESHOLD = 0.10;

    public function __construct(private readonly EmbeddingService $embeddingService)
    {
    }

    /**
     * @param array<string, mixed> $validated
     * @return array{
     *     status:int,
     *     message:string,
     *     created:bool,
     *     tag:DietaryTag|null,
     *     closest_match:array<string,mixed>|null
     * }
     */
    public function execute(Household $household, array $validated): array
    {
        $label = trim((string) $validated['label']);
        $type = (string) $validated['type'];
        $key = Str::slug($label);

        $existingTag = DietaryTag::query()
            ->where('type', $type)
            ->where('key', $key)
            ->first();

        if ($existingTag instanceof DietaryTag) {
            $household->dietaryTags()->syncWithoutDetaching([$existingTag->id]);

            return [
                'status' => 200,
                'message' => 'Ce tag existe déjà.',
                'created' => false,
                'tag' => $existingTag,
                'closest_match' => null,
            ];
        }

        $embedding = $this->embeddingService->generateVector($type . ': ' . $label);
        if (is_array($embedding)) {
            $closestMatch = $this->embeddingService->findClosestSemanticMatch(
                table: 'dietary_tags',
                vector: $embedding,
                whereClause: 'type = ? AND embedding IS NOT NULL',
                bindings: [$type],
                columns: ['id', 'key', 'label', 'type', 'is_system'],
            );

            if (is_array($closestMatch) && (float) ($closestMatch['distance'] ?? 1) <= self::DIETARY_TAG_SIMILARITY_THRESHOLD) {
                return [
                    'status' => 409,
                    'message' => 'Un tag très proche existe déjà.',
                    'created' => false,
                    'tag' => null,
                    'closest_match' => $closestMatch,
                ];
            }
        }

        $newTag = DietaryTag::query()->create([
            'type' => $type,
            'key' => $key,
            'label' => $label,
            'is_system' => false,
            'created_by_household_id' => $household->id,
            'embedding' => $this->embeddingService->serializeVector($embedding),
        ]);

        $household->dietaryTags()->syncWithoutDetaching([$newTag->id]);

        return [
            'status' => 201,
            'message' => 'Tag ajoute.',
            'created' => true,
            'tag' => $newTag,
            'closest_match' => null,
        ];
    }
}
