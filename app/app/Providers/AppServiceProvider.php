<?php

namespace App\Providers;

use App\Services\NotificationService;
use App\Models\NotificationChannel;
use App\Policies\NotificationChannelPolicy;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(NotificationService::class, function ($app) {
            return new NotificationService();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(NotificationChannel::class, NotificationChannelPolicy::class);
    }
}
