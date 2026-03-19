<?php

namespace App\Http\Resources\Tasks;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskMessageResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(private readonly string $message)
    {
        parent::__construct($message);
    }

    public static function makeMessage(string $message): self
    {
        return new self($message);
    }

    /**
     * @return array<string, string>
     */
    public function toArray(Request $request): array
    {
        return [
            'message' => $this->message,
        ];
    }
}
