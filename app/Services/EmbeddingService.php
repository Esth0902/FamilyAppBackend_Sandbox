<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use OpenAI\Laravel\Facades\OpenAI;

class EmbeddingService
{
    private const DEFAULT_DIMENSIONS = 512;

    /**
     * @var array<int, string>
     */
    private const ALLOWED_TABLES = [
        'dietary_tags',
        'recipes',
        'ingredients',
        'shopping_list_items',
    ];

    /**
     * @return array<int, float>|null
     */
    public function generateVector(string $text, int $dimensions = self::DEFAULT_DIMENSIONS): ?array
    {
        $input = trim($text);
        if ($input === '') {
            return null;
        }

        try {
            $response = OpenAI::embeddings()->create([
                'model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
                'input' => $input,
                'dimensions' => $dimensions,
            ]);

            $embedding = $response->embeddings[0]->embedding ?? null;
            if (!is_array($embedding) || count($embedding) !== $dimensions) {
                return null;
            }

            return array_map(static fn($value): float => (float) $value, $embedding);
        } catch (\Throwable $exception) {
            Log::warning('Embedding generation failed: ' . $exception->getMessage());
            return null;
        }
    }

    /**
     * @param array<int, float>|null $vector
     */
    public function serializeVector(?array $vector): ?string
    {
        if (!is_array($vector) || count($vector) === 0) {
            return null;
        }

        return $this->toVectorLiteral($vector);
    }

    /**
     * @param array<int, float> $vector
     * @param array<int, string> $columns
     * @param array<int, mixed> $bindings
     * @return array<string, mixed>|null
     */
    public function findClosestSemanticMatch(
        string $table,
        array $vector,
        ?string $whereClause = null,
        array $bindings = [],
        array $columns = ['id']
    ): ?array {
        if (count($vector) === 0) {
            return null;
        }

        $this->assertAllowedTable($table);
        $safeColumns = $this->sanitizeColumns($columns);
        $whereSql = trim((string) $whereClause) !== ''
            ? trim((string) $whereClause)
            : 'embedding IS NOT NULL';

        $vectorLiteral = $this->toVectorLiteral($vector);
        $selectSql = implode(', ', $safeColumns);

        $query = sprintf(
            'SELECT %s, (embedding <=> ?::vector) AS distance
             FROM %s
             WHERE %s
             ORDER BY embedding <=> ?::vector
             LIMIT 1',
            $selectSql,
            $table,
            $whereSql
        );

        $rows = DB::select($query, array_merge([$vectorLiteral], $bindings, [$vectorLiteral]));
        if (count($rows) === 0) {
            return null;
        }

        $match = (array) $rows[0];
        if (array_key_exists('distance', $match)) {
            $match['distance'] = round((float) $match['distance'], 4);
        }

        return $match;
    }

    /**
     * @param array<int, float> $vector
     */
    private function toVectorLiteral(array $vector): string
    {
        return '[' . implode(',', array_map(
            static fn(float $value): string => rtrim(rtrim(sprintf('%.8F', $value), '0'), '.'),
            $vector
        )) . ']';
    }

    private function assertAllowedTable(string $table): void
    {
        if (!in_array($table, self::ALLOWED_TABLES, true)) {
            throw new InvalidArgumentException("Table vectorielle non autorisée: {$table}");
        }
    }

    /**
     * @param array<int, string> $columns
     * @return array<int, string>
     */
    private function sanitizeColumns(array $columns): array
    {
        if (count($columns) === 0) {
            return ['id'];
        }

        return array_map(static function (string $column): string {
            if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $column)) {
                throw new InvalidArgumentException("Nom de colonne vectorielle invalide: {$column}");
            }

            return $column;
        }, $columns);
    }
}

