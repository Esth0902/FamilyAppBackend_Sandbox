<?php

namespace App\Services;

use App\Models\AiUsageLog;
use App\Models\Household;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AiUsageLogger
{
    public function logSuccess(
        string $requestType,
        mixed $response,
        ?string $model = null,
        ?int $latencyMs = null,
        ?int $householdId = null,
        ?int $userId = null,
    ): void {
        try {
            $payload = $this->toArray($response);
            $meta = $this->extractMeta($response);

            $resolvedModel = $this->resolveModel($payload, $model);
            [$inputTokens, $outputTokens, $totalTokens] = $this->extractTokens($payload, $meta);

            AiUsageLog::query()->create([
                'household_id' => $householdId ?? $this->resolveHouseholdId(),
                'user_id' => $userId ?? $this->resolveUserId(),
                'external_id' => $this->resolveExternalId($payload, $meta),
                'provider' => $this->resolveProvider($payload, $resolvedModel),
                'model' => $resolvedModel,
                'request_type' => $requestType,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'total_tokens' => $totalTokens,
                'cost_usd' => $this->extractCost($payload, $meta),
                'latency_ms' => $latencyMs,
                'is_error' => false,
                'error_message' => null,
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Impossible d\'enregistrer le log IA (success): ' . $exception->getMessage());
        }
    }

    public function logError(
        string $requestType,
        ?string $model,
        string $errorMessage,
        ?int $latencyMs = null,
        ?int $householdId = null,
        ?int $userId = null,
    ): void {
        try {
            $resolvedModel = $this->resolveModel([], $model);

            AiUsageLog::query()->create([
                'household_id' => $householdId ?? $this->resolveHouseholdId(),
                'user_id' => $userId ?? $this->resolveUserId(),
                'external_id' => null,
                'provider' => $this->resolveProvider([], $resolvedModel),
                'model' => $resolvedModel,
                'request_type' => $requestType,
                'input_tokens' => 0,
                'output_tokens' => 0,
                'total_tokens' => 0,
                'cost_usd' => 0,
                'latency_ms' => $latencyMs,
                'is_error' => true,
                'error_message' => mb_substr(trim($errorMessage), 0, 65535),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Impossible d\'enregistrer le log IA (error): ' . $exception->getMessage());
        }
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function extractTokens(array $payload, array $meta): array
    {
        $inputTokens = $this->toInt(
            data_get($payload, 'usage.prompt_tokens')
                ?? data_get($payload, 'usage.input_tokens')
                ?? data_get($payload, 'usage.input_tokens_details.text_tokens')
        );

        $outputTokens = $this->toInt(
            data_get($payload, 'usage.completion_tokens')
                ?? data_get($payload, 'usage.output_tokens')
                ?? data_get($payload, 'usage.output_tokens_details.text_tokens')
        );

        if ($inputTokens === 0 && $outputTokens === 0) {
            $openRouterUsage = $this->decodeJsonString(data_get($meta, 'custom.x-openrouter-usage'));
            $inputTokens = $this->toInt(data_get($openRouterUsage, 'prompt_tokens'));
            $outputTokens = $this->toInt(data_get($openRouterUsage, 'completion_tokens'));
        }

        $totalTokens = $this->toInt(data_get($payload, 'usage.total_tokens'));
        if ($totalTokens === 0) {
            $totalTokens = $inputTokens + $outputTokens;
        }

        return [$inputTokens, $outputTokens, $totalTokens];
    }

    private function extractCost(array $payload, array $meta): float
    {
        $costCandidates = [
            data_get($payload, 'usage.cost'),
            data_get($payload, 'usage.total_cost'),
            data_get($payload, 'usage.cost_usd'),
            data_get($payload, 'cost'),
            data_get($meta, 'custom.x-openrouter-cost'),
        ];

        foreach ($costCandidates as $candidate) {
            $cost = $this->toFloat($candidate);
            if ($cost > 0) {
                return $cost;
            }
        }

        $openRouterUsage = $this->decodeJsonString(data_get($meta, 'custom.x-openrouter-usage'));
        $fallbackCost = $this->toFloat(data_get($openRouterUsage, 'cost'));

        return $fallbackCost > 0 ? $fallbackCost : 0.0;
    }

    private function resolveProvider(array $payload, ?string $model): string
    {
        $rawProvider = data_get($payload, 'provider');
        if (is_array($rawProvider)) {
            $rawProvider = data_get($rawProvider, 'name')
                ?? data_get($rawProvider, 'id')
                ?? data_get($rawProvider, 'provider')
                ?? '';
        }

        $provider = trim((string) $rawProvider);
        if ($provider !== '') {
            return mb_substr($provider, 0, 255);
        }

        $model = trim((string) $model);
        if ($model !== '' && str_contains($model, '/')) {
            return mb_substr(strtok($model, '/'), 0, 255);
        }

        $baseUri = (string) config('openai.base_uri', '');
        if (str_contains(mb_strtolower($baseUri), 'openrouter')) {
            return 'openrouter';
        }

        return 'openai';
    }

    private function resolveExternalId(array $payload, array $meta): ?string
    {
        $externalId = trim((string) (data_get($payload, 'id') ?? data_get($meta, 'x-request-id') ?? ''));
        if ($externalId === '') {
            return null;
        }

        return mb_substr($externalId, 0, 255);
    }

    private function resolveModel(array $payload, ?string $fallback): string
    {
        $model = trim((string) (data_get($payload, 'model') ?? $fallback ?? 'unknown'));
        if ($model === '') {
            return 'unknown';
        }

        return mb_substr($model, 0, 255);
    }

    private function resolveHouseholdId(): ?int
    {
        $request = request();
        if (!$request) {
            return null;
        }

        $household = $request->attributes->get('current_household');
        if ($household instanceof Household && $household->id) {
            return (int) $household->id;
        }

        return null;
    }

    private function resolveUserId(): ?int
    {
        $userId = Auth::id();
        if (!is_int($userId) || $userId <= 0) {
            return null;
        }

        return $userId;
    }

    private function toInt(mixed $value): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }

        if (is_numeric($value)) {
            return max(0, (int) round((float) $value));
        }

        return 0;
    }

    private function toFloat(mixed $value): float
    {
        if (is_float($value) || is_int($value)) {
            return max(0.0, (float) $value);
        }

        if (is_string($value)) {
            $normalized = str_replace(['$', 'USD', 'usd', ' '], '', trim($value));
            $normalized = str_replace(',', '.', $normalized);
            if (is_numeric($normalized)) {
                return max(0.0, (float) $normalized);
            }
        }

        return 0.0;
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(mixed $response): array
    {
        if (is_array($response)) {
            return $response;
        }

        if (is_object($response) && method_exists($response, 'toArray')) {
            $mapped = $response->toArray();
            return is_array($mapped) ? $mapped : [];
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function extractMeta(mixed $response): array
    {
        if (!is_object($response) || !method_exists($response, 'meta')) {
            return [];
        }

        try {
            $meta = $response->meta();
            if (is_object($meta) && method_exists($meta, 'toArray')) {
                $mapped = $meta->toArray();
                return is_array($mapped) ? $mapped : [];
            }
        } catch (\Throwable) {
            return [];
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonString(mixed $raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
