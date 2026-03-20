<?php

namespace App\Providers;

use App\Models\MealPoll;
use App\Models\Recipe;
use App\Models\UserNotification;
use App\Policies\MealPollPolicy;
use App\Policies\RecipePolicy;
use App\Policies\UserNotificationPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        Recipe::class => RecipePolicy::class,
        MealPoll::class => MealPollPolicy::class,
        UserNotification::class => UserNotificationPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
