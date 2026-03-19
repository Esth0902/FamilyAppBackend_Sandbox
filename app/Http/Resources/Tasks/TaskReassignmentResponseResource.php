<?php

namespace App\Http\Resources\Tasks;

use App\Models\UserNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskReassignmentResponseResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(private readonly UserNotification $invitation)
    {
        parent::__construct($invitation);
    }

    public static function sent(UserNotification $invitation): self
    {
        return new self($invitation);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'message' => 'Demande envoyée.',
            'invitation' => TaskInvitationResource::make($this->invitation)->resolve($request),
        ];
    }
}
