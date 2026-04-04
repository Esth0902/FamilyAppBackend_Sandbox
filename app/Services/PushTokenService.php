<?php

namespace App\Services;

use App\Models\UserPushToken;

class PushTokenService
{
    public function registerToken(
        int $userId,
        string $token,
        ?string $platform = null,
        ?string $deviceName = null
    ): UserPushToken {
        $normalizedToken = trim($token);
        $normalizedPlatform = $this->normalizeNullableString($platform);
        $normalizedDeviceName = $this->normalizeNullableString($deviceName);

        $existing = UserPushToken::query()
            ->where('token', $normalizedToken)
            ->first();

        if ($existing instanceof UserPushToken) {
            $existing->forceFill([
                'user_id' => $userId,
                'platform' => $normalizedPlatform,
                'device_name' => $normalizedDeviceName,
                'is_active' => true,
                'last_seen_at' => now(),
            ])->save();

            return $existing;
        }

        return UserPushToken::query()->create([
            'user_id' => $userId,
            'token' => $normalizedToken,
            'platform' => $normalizedPlatform,
            'device_name' => $normalizedDeviceName,
            'is_active' => true,
            'last_seen_at' => now(),
        ]);
    }

    public function revokeToken(int $userId, ?string $token = null): int
    {
        $query = UserPushToken::query()
            ->where('user_id', $userId)
            ->where('is_active', true);

        $normalizedToken = $this->normalizeNullableString($token);
        if ($normalizedToken !== null) {
            $query->where('token', $normalizedToken);
        }

        return $query->update([
            'is_active' => false,
            'updated_at' => now(),
        ]);
    }

    private function normalizeNullableString(?string $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalized = trim($value);
        return $normalized === '' ? null : $normalized;
    }
}

