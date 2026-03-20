<?php

namespace App\Http\Resources\Tasks;

use App\Models\TaskTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskTemplateMutationResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(
        TaskTemplate $template,
        private readonly string $message,
    ) {
        parent::__construct($template);
    }

    public static function created(TaskTemplate $template): self
    {
        return new self($template, 'Template de tâche créé.');
    }

    public static function updated(TaskTemplate $template): self
    {
        return new self($template, 'Template de tâche mis à jour.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'message' => $this->message,
            'template' => TaskTemplateResource::make($this->resource)->resolve($request),
        ];
    }
}
