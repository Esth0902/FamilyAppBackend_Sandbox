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
     * @return array{status:int,payload:array<string,mixed>}
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
                'payload' => [
                    'message' => 'Ce tag existe deja.',
                    'created' => false,
                    'tag' => $this->formatTagPayload($existingTag),
                ],
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
                    'payload' => [
                        'message' => 'Un tag tres proche existe deja.',
                        'created' => false,
                        'closest_tag' => [
                            'id' => (int) ($closestMatch['id'] ?? 0),
                            'key' => (string) ($closestMatch['key'] ?? ''),
                            'label' => (string) ($closestMatch['label'] ?? ''),
                            'type' => (string) ($closestMatch['type'] ?? ''),
                            'is_system' => (bool) ($closestMatch['is_system'] ?? false),
                            'distance' => round((float) ($closestMatch['distance'] ?? 0), 4),
                        ],
                    ],
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
            'payload' => [
                'message' => 'Tag ajoute.',
                'created' => true,
                'tag' => $this->formatTagPayload($newTag),
            ],
        ];
    }

    /**
     * @return array{id:int,type:string,key:string,label:string,is_system:bool}
     */
    private function formatTagPayload(DietaryTag $tag): array
    {
        return [
            'id' => (int) $tag->id,
            'type' => (string) $tag->type,
            'key' => (string) $tag->key,
            'label' => (string) $tag->label,
            'is_system' => (bool) $tag->is_system,
        ];
    }
}

