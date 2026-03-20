<?php

namespace App\Http\Resources\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthSuccessResource extends JsonResource
{
    public static $wrap = null;

    public static function fromPayload(User $user, string $accessToken): self
    {
        return self::make([
            'user' => $user,
            'access_token' => $accessToken,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->resource['user'] ?? null;

        return [
            'access_token' => (string) ($this->resource['access_token'] ?? ''),
            'token_type' => 'Bearer',
            'user' => $user instanceof User
                ? UserProfileResource::make($user)->resolve($request)
                : null,
        ];
    }
}
