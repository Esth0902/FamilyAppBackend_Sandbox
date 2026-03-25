<?php

use App\Services\PollNotificationScheduler;
use App\Services\BudgetNotificationScheduler;
use App\Services\TaskNotificationScheduler;

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function (): void {
    app(PollNotificationScheduler::class)->run();
    app(BudgetNotificationScheduler::class)->run();
    app(TaskNotificationScheduler::class)->run();
})->everyMinute();

Schedule::command('notifications:purge-old')->dailyAt('03:00');
