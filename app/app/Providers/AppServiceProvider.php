<?php

namespace App\Providers;

use App\Models\BackupServer;
use App\Models\NotificationChannel;
use App\Policies\BackupServerPolicy;
use App\Policies\NotificationChannelPolicy;
use App\Services\BackupHealthService;
use App\Services\NotificationService;
use App\Services\RemoteControlConnectionHandler;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(NotificationService::class);

        $this->app->singleton(RemoteControlConnectionHandler::class);

        // One instance per request/job, so dashboard components share its memoized data.
        $this->app->scoped(BackupHealthService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(NotificationChannel::class, NotificationChannelPolicy::class);
        Gate::policy(BackupServer::class, BackupServerPolicy::class);
    }
}
