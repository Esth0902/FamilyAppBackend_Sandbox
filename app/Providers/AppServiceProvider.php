<?php

namespace App\Providers;

use App\Routing\Utf8SafeResponseFactory;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Routing\ResponseFactory as ResponseFactoryContract;
use Illuminate\Contracts\View\Factory as ViewFactoryContract;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Routing\ResponseFactory as IlluminateResponseFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ResponseFactoryContract::class, function ($app) {
            return new Utf8SafeResponseFactory($app[ViewFactoryContract::class], $app['redirect']);
        });

        $this->app->alias(ResponseFactoryContract::class, IlluminateResponseFactory::class);
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
            $frontendBaseUrl = (string) (config('app.frontend_url') ?: config('app.url'));
            // Keep URI schemes such as "frontend://" intact while normalizing classic trailing slashes.
            $frontendBaseUrl = preg_replace('#(?<!:)/+$#', '', $frontendBaseUrl) ?: $frontendBaseUrl;
            $separator = Str::endsWith($frontendBaseUrl, '://') ? '' : '/';
            $email = urlencode((string) $notifiable->getEmailForPasswordReset());

            return $frontendBaseUrl . "{$separator}password-reset/$token?email=$email";
        });
    }
}
