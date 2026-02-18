<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            $perMinute = max(1, (int) env('API_RATE_LIMIT_PER_MINUTE', 120));

            return Limit::perMinute($perMinute)
                ->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('ai', function (Request $request) {
            $perMinute = max(1, (int) env('API_AI_RATE_LIMIT_PER_MINUTE', 20));

            return Limit::perMinute($perMinute)
                ->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('broadcast', function (Request $request) {
            $perMinute = max(1, (int) env('API_BROADCAST_RATE_LIMIT_PER_MINUTE', 180));

            return Limit::perMinute($perMinute)
                ->by($request->user()?->id ?: $request->ip());
        });

        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            $frontendBaseUrl = rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/');
            $email = urlencode((string) $notifiable->getEmailForPasswordReset());

            return $frontendBaseUrl . "/password-reset/$token?email=$email";
        });
    }
}
