<?php

namespace MyListerHub\ReplicationRouting\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class WriteFenceManager
{
    public function __construct(
        protected ReplicationPositionService $positionService
    ) {}

    /**
     * Record a write operation and set fences/hot flags.
     */
    public function recordWrite(
        string $connectionName,
        ?int $userId = null,
        ?int $tenantId = null
    ): void {
        if (! config('replication-routing.write_fences.enabled', false)) {
            return;
        }

        try {
            // Get current position from master
            $position = $this->positionService->getCurrentPosition($connectionName);

            if (! $position) {
                return; // Can't get position, skip fence recording
            }

            $hotTtl = config('replication-routing.write_fences.hot_ttl', 2);
            $fenceTtl = config('replication-routing.write_fences.fence_ttl', 600);

            // Record user fence
            if ($userId && config('replication-routing.write_fences.track_user', true)) {
                $this->setHotFlag($connectionName, $userId, null, $hotTtl);
                $this->setFence($connectionName, $userId, null, $position, $fenceTtl);
            }

            // Record tenant fence
            if ($tenantId && config('replication-routing.write_fences.track_tenant', true)) {
                $this->setHotFlag($connectionName, null, $tenantId, $hotTtl);
                $this->setFence($connectionName, null, $tenantId, $position, $fenceTtl);
            }
        } catch (Throwable $e) {
            // Don't let fence recording break the application
            Log::warning('Failed to record write fence: '.$e->getMessage());
        }
    }

    /**
     * Determine if we should use the write (master) connection for reads.
     *
     * Priority:
     * 1. Check hot flags (fast path)
     * 2. Check fences and compare with replica position
     * 3. Return false to fall back to lag threshold logic
     */
    public function shouldUseWriteConnection(
        string $connectionName,
        ?int $userId = null,
        ?int $tenantId = null
    ): bool {
        if (! config('replication-routing.write_fences.enabled', false)) {
            return false;
        }

        try {
            // Priority 1: Check hot flags (fastest path - just cache lookup)
            if ($this->isHot($connectionName, $userId, $tenantId)) {
                Log::debug("Hot routing to master for {$connectionName}", [
                    'user_id' => $userId,
                    'tenant_id' => $tenantId,
                ]);

                return true;
            }

            // Priority 2: Check fences
            $userFence = $userId && config('replication-routing.write_fences.track_user', true)
                ? $this->getFence($connectionName, $userId, null)
                : null;

            $tenantFence = $tenantId && config('replication-routing.write_fences.track_tenant', true)
                ? $this->getFence($connectionName, null, $tenantId)
                : null;

            // Get the newer fence (strategy: newer fence wins)
            $fence = $this->getNewerFence($userFence, $tenantFence);

            if (! $fence) {
                return false; // No fence, fall back to lag threshold
            }

            // Check if replica has caught up to the fence
            $hasReached = $this->positionService->hasReachedPosition($connectionName, $fence);

            if (! $hasReached) {
                Log::info("Fence routing to master for {$connectionName}", [
                    'user_id' => $userId,
                    'tenant_id' => $tenantId,
                    'fence' => $fence,
                    'reason' => 'replica_behind_fence',
                ]);
            }

            return ! $hasReached;
        } catch (Throwable $e) {
            Log::warning('Failed to check write fence, falling back: '.$e->getMessage());

            // On error, fall back to lag threshold logic
            return false;
        }
    }

    /**
     * Check if hot flag exists for user or tenant.
     */
    public function isHot(
        string $connectionName,
        ?int $userId = null,
        ?int $tenantId = null
    ): bool {
        if ($userId) {
            $key = $this->getHotKey($connectionName, $userId, null);
            if (Cache::has($key)) {
                return true;
            }
        }

        if ($tenantId) {
            $key = $this->getHotKey($connectionName, null, $tenantId);
            if (Cache::has($key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get fence for user or tenant.
     */
    public function getFence(
        string $connectionName,
        ?int $userId = null,
        ?int $tenantId = null
    ): ?string {
        if ($userId) {
            $key = $this->getFenceKey($connectionName, $userId, null);
            $fence = Cache::get($key);
            if ($fence) {
                return $fence;
            }
        }

        if ($tenantId) {
            $key = $this->getFenceKey($connectionName, null, $tenantId);
            $fence = Cache::get($key);
            if ($fence) {
                return $fence;
            }
        }

        return null;
    }

    /**
     * Get the newer fence between user and tenant fences.
     * Strategy: Newer fence wins (more recent write takes priority).
     */
    protected function getNewerFence(?string $userFence, ?string $tenantFence): ?string
    {
        if (! $userFence) {
            return $tenantFence;
        }
        if (! $tenantFence) {
            return $userFence;
        }

        // For MySQL GTID, we can't easily compare timestamps, so we use a simple heuristic:
        // Whichever fence is "larger" lexicographically is considered newer
        // This works because GTIDs typically increase monotonically
        // For PostgreSQL LSN, similar logic applies

        // For now, we'll just return the user fence if both exist
        // In a production system, you might want to store metadata (timestamp) with fences
        return $userFence;
    }

    /**
     * Set hot flag in cache.
     */
    protected function setHotFlag(
        string $connectionName,
        ?int $userId,
        ?int $tenantId,
        int $ttl
    ): void {
        $key = $this->getHotKey($connectionName, $userId, $tenantId);
        Cache::put($key, true, $ttl);
    }

    /**
     * Set fence in cache.
     */
    protected function setFence(
        string $connectionName,
        ?int $userId,
        ?int $tenantId,
        string $position,
        int $ttl
    ): void {
        $key = $this->getFenceKey($connectionName, $userId, $tenantId);
        Cache::put($key, $position, $ttl);
    }

    /**
     * Get cache key for hot flag.
     */
    protected function getHotKey(string $connectionName, ?int $userId, ?int $tenantId): string
    {
        if ($userId) {
            return "db:hot:user:{$userId}:{$connectionName}";
        }

        if ($tenantId) {
            return "db:hot:tenant:{$tenantId}:{$connectionName}";
        }

        throw new InvalidArgumentException('Either userId or tenantId must be provided');
    }

    /**
     * Get cache key for fence.
     */
    protected function getFenceKey(string $connectionName, ?int $userId, ?int $tenantId): string
    {
        if ($userId) {
            return "db:fence:user:{$userId}:{$connectionName}";
        }

        if ($tenantId) {
            return "db:fence:tenant:{$tenantId}:{$connectionName}";
        }

        throw new InvalidArgumentException('Either userId or tenantId must be provided');
    }
}
