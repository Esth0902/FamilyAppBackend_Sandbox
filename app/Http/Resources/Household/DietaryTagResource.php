<?php

namespace App\Http\Resources\Household;

use App\Models\DietaryTag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DietaryTag */
class DietaryTagResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'type' => (string) $this->type,
            'key' => (string) $this->key,
            'label' => (string) $this->label,
            'is_system' => (bool) $this->is_system,
        ];
    }

    /**
     * @param array<string, mixed> $closestMatch
     * @return array{id:int,key:string,label:string,type:string,is_system:bool,distance:float}
     */
    public static function closestMatchPayload(array $closestMatch): array
    {
        return [
            'id' => (int) ($closestMatch['id'] ?? 0),
            'key' => (string) ($closestMatch['key'] ?? ''),
            'label' => (string) ($closestMatch['label'] ?? ''),
            'type' => (string) ($closestMatch['type'] ?? ''),
            'is_system' => (bool) ($closestMatch['is_system'] ?? false),
            'distance' => round((float) ($closestMatch['distance'] ?? 0), 4),
        ];
    }
}

