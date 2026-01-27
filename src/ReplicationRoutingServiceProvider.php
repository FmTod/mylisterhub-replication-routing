<?php

namespace MyListerHub\ReplicationRouting;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\ServiceProvider;
use MyListerHub\ReplicationRouting\Http\Middleware\ResolveReplicationRouting;
use MyListerHub\ReplicationRouting\Listeners\RecordDatabaseWrite;
use MyListerHub\ReplicationRouting\Providers\ReplicationLagServiceProvider;
use MyListerHub\ReplicationRouting\Services\ReplicationLagChecker;
use MyListerHub\ReplicationRouting\Services\ReplicationPositionService;
use MyListerHub\ReplicationRouting\Services\WriteFenceManager;

class ReplicationRoutingServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Merge package configuration
        $this->mergeConfigFrom(
            __DIR__.'/../config/replication-routing.php',
            'replication-routing'
        );

        // Register services
        $this->app->singleton(ReplicationPositionService::class);
        $this->app->singleton(ReplicationLagChecker::class);
        $this->app->singleton(WriteFenceManager::class);

        // Register lag threshold provider
        $this->app->register(ReplicationLagServiceProvider::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publish configuration
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/replication-routing.php' => config_path('replication-routing.php'),
            ], 'replication-routing-config');
        }

        // Register event listener for write fence tracking
        if (config('replication-routing.write_fences.enabled', false)) {
            $this->app['events']->listen(QueryExecuted::class, RecordDatabaseWrite::class);
        }

        // Middleware is registered in the application's bootstrap/app.php
        // Users need to add: ResolveReplicationRouting::class to middleware priority
    }
}
