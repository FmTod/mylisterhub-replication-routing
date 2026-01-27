<?php

namespace MyListerHub\ReplicationRouting\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReplicationPositionService
{
    /**
     * Get the current replication position from the master connection.
     *
     * @return string|null GTID for MySQL/MariaDB, LSN for PostgreSQL, null on failure
     */
    public function getCurrentPosition(string $connectionName): ?string
    {
        try {
            $connection = DB::connection($connectionName);
            $driver = $connection->getDriverName();

            if (in_array($driver, ['mysql', 'mariadb'])) {
                return $this->getMySQLPosition($connection);
            }

            if ($driver === 'pgsql') {
                return $this->getPostgreSQLPosition($connection);
            }

            Log::warning("Unsupported database driver for replication position: {$driver}");

            return null;
        } catch (Throwable $e) {
            Log::warning("Failed to get current replication position for {$connectionName}: ".$e->getMessage());

            return null;
        }
    }

    /**
     * Get the current replication position from a replica.
     *
     * @return string|null GTID for MySQL/MariaDB, LSN for PostgreSQL, null on failure
     */
    public function getReplicaPosition(string $connectionName): ?string
    {
        try {
            $connection = DB::connection($connectionName);
            $driver = $connection->getDriverName();

            if (in_array($driver, ['mysql', 'mariadb'])) {
                // For MySQL/MariaDB, we use the same @@global.gtid_executed on replica
                return $this->getMySQLPosition($connection);
            }

            if ($driver === 'pgsql') {
                return $this->getPostgreSQLReplicaPosition($connection);
            }

            return null;
        } catch (Throwable $e) {
            Log::warning("Failed to get replica position for {$connectionName}: ".$e->getMessage());

            return null;
        }
    }

    /**
     * Check if the replica has reached the specified position (fence).
     *
     * @param  string  $fence  The target position (GTID or LSN)
     * @return bool True if replica has caught up, false otherwise
     */
    public function hasReachedPosition(string $connectionName, string $fence): bool
    {
        try {
            $connection = DB::connection($connectionName);
            $driver = $connection->getDriverName();

            if (in_array($driver, ['mysql', 'mariadb'])) {
                return $this->hasMySQLReachedPosition($connection, $fence);
            }

            if ($driver === 'pgsql') {
                return $this->hasPostgreSQLReachedPosition($connection, $fence);
            }

            // Unknown driver, assume not reached (safe fallback to master)
            return false;
        } catch (Throwable $e) {
            Log::warning("Failed to check if replica reached position for {$connectionName}: ".$e->getMessage());

            // On error, assume not reached (safe fallback to master)
            return false;
        }
    }

    /**
     * Get MySQL/MariaDB GTID position.
     */
    protected function getMySQLPosition($connection): ?string
    {
        $result = $connection->selectOne('SELECT @@global.gtid_executed AS gtid');

        if (! $result || empty($result->gtid)) {
            return null;
        }

        return $result->gtid;
    }

    /**
     * Get PostgreSQL WAL LSN position (master).
     */
    protected function getPostgreSQLPosition($connection): ?string
    {
        $result = $connection->selectOne('SELECT pg_current_wal_lsn() AS lsn');

        if (! $result || empty($result->lsn)) {
            return null;
        }

        return $result->lsn;
    }

    /**
     * Get PostgreSQL WAL LSN position (replica).
     */
    protected function getPostgreSQLReplicaPosition($connection): ?string
    {
        $result = $connection->selectOne('SELECT pg_last_wal_replay_lsn() AS lsn');

        if (! $result || empty($result->lsn)) {
            return null;
        }

        return $result->lsn;
    }

    /**
     * Check if MySQL/MariaDB replica has reached the fence position.
     */
    protected function hasMySQLReachedPosition($connection, string $fence): bool
    {
        // Use GTID_SUBSET to check if fence GTIDs are a subset of executed GTIDs
        // GTID_SUBSET returns 1 if all GTIDs in fence have been executed
        $result = $connection->selectOne(
            'SELECT GTID_SUBSET(?, @@global.gtid_executed) AS caught_up',
            [$fence]
        );

        return ! empty($result->caught_up);
    }

    /**
     * Check if PostgreSQL replica has reached the fence LSN.
     */
    protected function hasPostgreSQLReachedPosition($connection, string $fence): bool
    {
        $replicaLsn = $this->getPostgreSQLReplicaPosition($connection);

        if (! $replicaLsn) {
            return false;
        }

        // Convert LSN strings to comparable integers
        $fenceInt = $this->lsnToInt($fence);
        $replicaInt = $this->lsnToInt($replicaLsn);

        return $replicaInt >= $fenceInt;
    }

    /**
     * Convert PostgreSQL LSN (e.g., "0/3000000") to integer for comparison.
     */
    protected function lsnToInt(string $lsn): int
    {
        $parts = explode('/', $lsn);

        if (count($parts) !== 2) {
            return 0;
        }

        $high = hexdec($parts[0]);
        $low = hexdec($parts[1]);

        // Combine into a single 64-bit integer
        return ($high << 32) | $low;
    }
}
