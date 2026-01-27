<?php

namespace MyListerHub\ReplicationRouting\Providers;

use MyListerHub\ReplicationRouting\Services\ReplicationLagChecker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Throwable;

class ReplicationLagServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(ReplicationLagChecker::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        if (! config('replication-routing.lag_threshold.enabled', false)) {
            return;
        }

        $connections = config('replication-routing.connections', []);
        $threshold = (int) config('replication-routing.lag_threshold.threshold', 1);
        $checkInterval = (int) config('replication-routing.lag_threshold.check_interval', 60);

        // Initial check on boot
        foreach ($connections as $connectionName) {
            $this->checkAndConfigureConnection($connectionName, $threshold, $checkInterval);
        }

        // Octane support: Re-check on every request to handle long-running processes
        $octaneEvent = 'Laravel\Octane\Events\RequestReceived';
        if (class_exists($octaneEvent)) {
            $this->app['events']->listen($octaneEvent, function () use ($connections, $threshold, $checkInterval) {
                foreach ($connections as $connectionName) {
                    $this->checkAndConfigureConnection($connectionName, $threshold, $checkInterval);
                }
            });
        }
    }

    /**
     * Check lag and configure connection accordingly.
     */
    protected function checkAndConfigureConnection(string $name, int $threshold, int $interval): void
    {
        try {
            $cacheKey = "db.replication.lag.{$name}";

            // Try to get cached lag status
            $lagStatus = Cache::get($cacheKey);

            // If cache miss or expired, check lag
            if ($lagStatus === null) {
                /** @var ReplicationLagChecker $checker */
                $checker = $this->app->make(ReplicationLagChecker::class);
                $secondsBehind = $checker->checkLag($name);

                // Logic:
                // - If secondsBehind is NULL (error/broken), we default to safe mode (master) if strictly configured,
                //   BUT per requirement "Fallback to default behavior" (read/write separation), we treat NULL as "not lagging"
                //   OR we treat it as "unknown".
                //   Let's check the user requirement: "2. Fallback to default behavior".
                //   Default behavior = Use configured read/write.
                //   So if we fail to check, we DO NOTHING (is_lagging = false).

                $isLagging = $secondsBehind !== null && $secondsBehind > $threshold;

                $lagStatus = [
                    'seconds_behind' => $secondsBehind,
                    'checked_at' => now()->timestamp,
                    'is_lagging' => $isLagging,
                ];

                // Cache for the interval
                Cache::put($cacheKey, $lagStatus, $interval);

                // Log only when we detect lag to reduce noise, or if debug is on
                if ($isLagging) {
                    Log::info("Replication lag detected for {$name}: {$secondsBehind}s behind master. Switching to write connection for reads.");
                }
            }

            // Apply connection strategy
            // IMPORTANT: Explicitly set false if not lagging to reset connection state in long-running processes (Octane)
            $shouldUseWriteConnection = ! empty($lagStatus['is_lagging']);
            DB::connection($name)->useWriteConnectionWhenReading($shouldUseWriteConnection);
        } catch (Throwable $e) {
            // Safety net: if anything goes wrong in the boot process (e.g. cache down),
            // log it and continue with default application behavior (don't crash boot).
            Log::warning("ReplicationLagServiceProvider failed for {$name}: ".$e->getMessage());
        }
    }
}
