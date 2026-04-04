<?php

namespace App\Services;

use App\Models\UserPushToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PushNotificationService
{
    /**
     * @param array<string, mixed> $data
     */
    public function sendToUser(
        int $userId,
        string $title,
        string $body,
        array $data = []
    ): void {
        if ($userId <= 0 || app()->runningUnitTests()) {
            return;
        }

        $enabled = (bool) config('services.expo_push.enabled', true);
        if (!$enabled) {
            return;
        }

        $tokens = UserPushToken::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->pluck('token')
            ->map(static fn ($token): string => trim((string) $token))
            ->filter(static fn (string $token): bool => $token !== '')
            ->unique()
            ->values()
            ->all();

        if (count($tokens) === 0) {
            return;
        }

        $endpoint = (string) config('services.expo_push.endpoint', 'https://exp.host/--/api/v2/push/send');
        if (trim($endpoint) === '') {
            return;
        }

        $messages = collect($tokens)
            ->map(static fn (string $token): array => [
                'to' => $token,
                'title' => $title,
                'body' => $body,
                'sound' => 'default',
                'channelId' => 'default',
                'data' => $data,
            ])
            ->values()
            ->all();

        try {
            $response = Http::acceptJson()
                ->timeout((int) config('services.expo_push.timeout', 8))
                ->post($endpoint, $messages);

            if (!$response->successful()) {
                Log::warning('Push Expo: échec HTTP', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'user_id' => $userId,
                ]);
                return;
            }

            $responseData = $response->json();
            if (!is_array($responseData)) {
                return;
            }

            $tickets = data_get($responseData, 'data');
            if (!is_array($tickets)) {
                return;
            }

            foreach ($tickets as $index => $ticket) {
                if (!is_array($ticket)) {
                    continue;
                }

                $status = (string) ($ticket['status'] ?? '');
                if ($status !== 'error') {
                    continue;
                }

                $errorCode = (string) data_get($ticket, 'details.error', '');
                if (!in_array($errorCode, ['DeviceNotRegistered', 'InvalidCredentials'], true)) {
                    continue;
                }

                $token = $tokens[$index] ?? null;
                if (!is_string($token) || trim($token) === '') {
                    continue;
                }

                UserPushToken::query()
                    ->where('token', trim($token))
                    ->update([
                        'is_active' => false,
                        'updated_at' => now(),
                    ]);
            }
        } catch (\Throwable $exception) {
            Log::warning('Push Expo: exception envoi', [
                'message' => $exception->getMessage(),
                'user_id' => $userId,
            ]);
        }
    }
}

