<?php

namespace MyListerHub\ReplicationRouting\Http\Middleware;

use MyListerHub\ReplicationRouting\Services\WriteFenceManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ResolveReplicationRouting
{
    public function __construct(
        protected WriteFenceManager $fenceManager
    ) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('replication-routing.enabled', true)) {
            return $next($request);
        }

        $connections = config('replication-routing.connections', []);

        // Get context
        $userId = auth()->id();
        $tenantId = function_exists('tenant') ? tenant()?->id : null;

        foreach ($connections as $connectionName) {
            $this->configureConnection($connectionName, $userId, $tenantId);
        }

        return $next($request);
    }

    /**
     * Configure routing for a specific connection.
     *
     * Priority:
     * 1. Write fences (hot flag or fence check)
     * 2. Global lag threshold (existing implementation)
     * 3. Default (use replicas)
     */
    protected function configureConnection(string $name, ?int $userId, ?int $tenantId): void
    {
        try {
            // Priority 1: Check write fences
            if (config('replication-routing.write_fences.enabled', false)) {
                if ($this->fenceManager->shouldUseWriteConnection($name, $userId, $tenantId)) {
                    DB::connection($name)->useWriteConnectionWhenReading(true);

                    return;
                }
            }

            // Priority 2: Check global lag threshold (existing implementation)
            if (config('replication-routing.lag_threshold.enabled', true)) {
                $lagStatus = Cache::get("db.replication.lag.{$name}");

                if (! empty($lagStatus['is_lagging'])) {
                    DB::connection($name)->useWriteConnectionWhenReading(true);

                    return;
                }
            }

            // Priority 3: Default - use replicas
            DB::connection($name)->useWriteConnectionWhenReading(false);
        } catch (Throwable $e) {
            // On error, fall back to default behavior (don't crash the request)
            Log::warning("ResolveReplicationRouting failed for {$name}: ".$e->getMessage());
        }
    }
}
