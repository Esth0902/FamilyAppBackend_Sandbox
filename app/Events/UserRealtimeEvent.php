<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserRealtimeEvent implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly int $userId,
        public readonly string $module,
        public readonly string $type,
        public readonly array $payload = [],
    ) {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('App.Models.User.' . $this->userId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'user.realtime';
    }

    public function broadcastWith(): array
    {
        return [
            'module' => $this->module,
            'type' => $this->type,
            'payload' => $this->payload,
            'emitted_at' => now()->toIso8601String(),
        ];
    }
}
