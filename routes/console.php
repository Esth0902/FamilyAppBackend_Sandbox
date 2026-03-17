<?php

use App\Services\PollNotificationScheduler;
use App\Services\BudgetNotificationScheduler;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function (): void {
    app(PollNotificationScheduler::class)->run();
    app(BudgetNotificationScheduler::class)->run();
})->everyMinute();
