<?php

namespace App\Providers;

use App\Http\Middleware\EnsureRemoteControlEnabled;
use App\Http\Middleware\EnsureTwoFactorConfigured;
use App\Models\BackupServer;
use App\Models\NotificationChannel;
use App\Policies\BackupServerPolicy;
use App\Policies\NotificationChannelPolicy;
use App\Services\BackupHealthService;
use App\Services\NotificationService;
use App\Services\RemoteControlConnectionHandler;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

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

        // REST API: per token owner, or per IP for unauthenticated requests.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));

        // Public status pages.
        RateLimiter::for('status-page', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));

        if ($proxies = config('app.trusted_proxies')) {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }

        // Re-apply these on Livewire action requests too, not only on the first page load.
        Livewire::addPersistentMiddleware([
            RequirePassword::class,
            EnsureTwoFactorConfigured::class,
            EnsureRemoteControlEnabled::class,
        ]);
    }
}
