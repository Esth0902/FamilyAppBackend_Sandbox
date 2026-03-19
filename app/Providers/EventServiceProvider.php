<?php

namespace App\Providers;

use App\Events\Budget\AdvanceRequestedEvent;
use App\Listeners\Notifications\CreateAdvanceNotificationListener;
use App\Listeners\Realtime\BroadcastAdvanceRealtimeListener;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        AdvanceRequestedEvent::class => [
            CreateAdvanceNotificationListener::class,
            BroadcastAdvanceRealtimeListener::class,
        ],
    ];

    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
