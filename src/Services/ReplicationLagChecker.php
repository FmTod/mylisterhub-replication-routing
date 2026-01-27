<?php

namespace MyListerHub\ReplicationRouting\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReplicationLagChecker
{
    /**
     * Check replication lag for a specific connection.
     *
     * @return int|null Seconds behind master, or null if cannot determine
     */
    public function checkLag(string $connectionName): ?int
    {
        try {
            // Get the read connection to check replica status
            $connection = DB::connection($connectionName);

            // If connection doesn't have read/write separation configured,
            // checking replication lag might not make sense or might fail if it's a single host.
            // However, we'll proceed with the query as some single hosts might still report status.

            // Try modern MySQL syntax first (8.0.22+)
            try {
                $status = $connection->selectOne('SHOW REPLICA STATUS');
            } catch (Throwable $e) {
                // Fallback to legacy syntax
                $status = $connection->selectOne('SHOW SLAVE STATUS');
            }

            if (! $status) {
                // No replication status found
                return null;
            }

            // Convert object to array for easier key access (case-insensitive handling might be needed)
            $statusArray = (array) $status;

            // Check for Seconds_Behind_Master (legacy/standard) or specific MySQL 8 keys
            // Note: Keys are typically case-sensitive in PHP array from object, but depend on PDO fetch mode.
            // We'll try common variations.
            $keys = ['Seconds_Behind_Master', 'seconds_behind_master'];

            foreach ($keys as $key) {
                if (array_key_exists($key, $statusArray)) {
                    $seconds = $statusArray[$key];

                    // If NULL, replication is broken or not started -> treat as lagging/unsafe (return null to let caller decide, or handle here)
                    // In our plan: NULL means we can't trust it. But often NULL in Seconds_Behind_Master means replication error.
                    // For safety, if we see the key but it's null, we return null.

                    if ($seconds === null) {
                        Log::warning("Replication lag check: {$connectionName} returned NULL for Seconds_Behind_Master. Replication might be broken.");

                        return null;
                    }

                    return (int) $seconds;
                }
            }

            return null;

        } catch (Throwable $e) {
            // Log error but don't crash the app
            Log::warning("Failed to check replication lag for connection {$connectionName}: ".$e->getMessage());

            return null;
        }
    }
}
