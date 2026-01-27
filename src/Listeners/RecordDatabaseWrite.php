<?php

namespace MyListerHub\ReplicationRouting\Listeners;

use MyListerHub\ReplicationRouting\Services\WriteFenceManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Str;

class RecordDatabaseWrite
{
    public function __construct(
        protected WriteFenceManager $fenceManager
    ) {}

    /**
     * Handle the event.
     */
    public function handle(QueryExecuted $event): void
    {
        // Check if write fence tracking is enabled
        if (! config('replication-routing.write_fences.enabled', false)) {
            return;
        }

        // Only track write queries
        if (! $this->isWriteQuery($event->sql)) {
            return;
        }

        // Check if this query should be tracked based on whitelist/blacklist
        if (! $this->shouldTrackQuery($event->sql)) {
            return;
        }

        // Get user and tenant context
        $userId = auth()->id();
        $tenantId = tenant()?->id;

        // Record the write
        $this->fenceManager->recordWrite(
            $event->connectionName,
            $userId,
            $tenantId
        );
    }

    /**
     * Determine if the query is a write operation.
     */
    protected function isWriteQuery(string $sql): bool
    {
        $sql = mb_strtoupper(mb_trim($sql));

        return Str::startsWith($sql, [
            'INSERT',
            'UPDATE',
            'DELETE',
            'REPLACE',
            'TRUNCATE',
        ]);
    }

    /**
     * Check if this query should be tracked based on whitelist/blacklist configuration.
     */
    protected function shouldTrackQuery(string $sql): bool
    {
        $whitelist = config('replication-routing.write_fences.table_whitelist', []);
        $blacklist = config('replication-routing.write_fences.table_blacklist', []);

        // If whitelist is configured, only track tables in the whitelist
        if (! empty($whitelist)) {
            return $this->queryMatchesTables($sql, $whitelist);
        }

        // If blacklist is configured, skip tables in the blacklist
        if (! empty($blacklist)) {
            return ! $this->queryMatchesTables($sql, $blacklist);
        }

        // No whitelist or blacklist configured, track all writes
        return true;
    }

    /**
     * Check if the query mentions any of the specified tables.
     */
    protected function queryMatchesTables(string $sql, array $tables): bool
    {
        $sql = mb_strtolower($sql);

        foreach ($tables as $table) {
            $table = mb_strtolower($table);

            // Simple pattern matching - checks if table name appears in query
            // This handles: INSERT INTO table, UPDATE table, DELETE FROM table
            if (Str::contains($sql, $table)) {
                return true;
            }
        }

        return false;
    }
}
