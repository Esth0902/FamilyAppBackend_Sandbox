<?php

namespace App\Http\Resources\Tasks;

use App\Models\TaskInstance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskInstanceMutationResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(
        TaskInstance $instance,
        private readonly string $message,
    ) {
        parent::__construct($instance);
    }

    public static function created(TaskInstance $instance): self
    {
        return new self($instance, 'Tâche créée.');
    }

    public static function updated(TaskInstance $instance): self
    {
        return new self($instance, 'Tâche mise à jour.');
    }

    public static function validated(TaskInstance $instance): self
    {
        return new self($instance, 'Tâche validée.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'message' => $this->message,
            'instance' => TaskInstanceResource::make($this->resource)->resolve($request),
        ];
    }
}
