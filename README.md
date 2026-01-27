# Laravel Replication Routing

> **Smart database replication routing for Laravel applications with read-your-writes guarantees**

This package provides intelligent routing between master and replica databases in Laravel, combining **lag threshold monitoring** (global safety net) with **write fences** (per-user/tenant read-your-writes guarantee) for optimal performance and data consistency.

## Features

- **🎯 Write Fences**: GTID/LSN-based tracking ensures users always read their own writes
- **⚡ Hot Flag Optimization**: Sub-millisecond routing decisions for recent writes
- **🛡️ Lag Threshold Safety Net**: Global fallback when replicas lag behind
- **🔄 Multi-Tenancy Support**: Per-user and per-tenant write tracking
- **📊 MySQL & PostgreSQL**: Supports both MySQL/MariaDB GTID and PostgreSQL LSN
- **⚙️ Flexible Configuration**: Table whitelist/blacklist, configurable TTLs
- **🚀 Production Ready**: Comprehensive test coverage, graceful error handling

## How It Works

### Three-Tier Routing Priority

1. **Hot Flags** (fastest): After a write, route to master for 2 seconds (configurable)
2. **Write Fences**: Check if replica has caught up to the write position (GTID/LSN)
3. **Lag Threshold**: Global check if replica is lagging behind threshold

### Architecture

```
User writes → QueryExecuted event → Record GTID/LSN fence
Next read → Check hot flag → Check fence → Check lag → Route decision
```

## Installation

### 1. Install the Package

This package is already included in your `composer.json` as a local module:

```bash
composer update mylisterhub/replication-routing
```

### 2. Publish Configuration

```bash
php artisan vendor:publish --tag=replication-routing-config
```

This creates `config/replication-routing.php`.

### 3. Register Middleware

Add the middleware to your `bootstrap/app.php` middleware priority list **after authentication**:

```php
use MyListerHub\ReplicationRouting\Http\Middleware\ResolveReplicationRouting;

$middleware->priority([
    // ... session, tenancy middleware
    Authenticate::class,
    AuthenticateSession::class,
    
    // Replication routing AFTER authentication
    ResolveReplicationRouting::class,
    
    SubstituteBindings::class,
    // ... other middleware
]);
```

### 4. Configure Environment

Add to your `.env` file:

```env
# Replication Routing
DB_REPLICATION_ROUTING_ENABLED=true

# Lag Threshold (Global Safety Net)
DB_CHECK_REPLICATION_LAG=true
DB_REPLICATION_LAG_THRESHOLD=1

# Write Fences (Read-Your-Writes Guarantee)
DB_WRITE_FENCES_ENABLED=false  # Start disabled, enable after testing
DB_WRITE_FENCE_HOT_TTL=2
DB_WRITE_FENCE_TTL=600
DB_WRITE_FENCE_TRACK_USER=true
DB_WRITE_FENCE_TRACK_TENANT=true

# Table Filtering
DB_WRITE_FENCE_TABLE_WHITELIST=
DB_WRITE_FENCE_TABLE_BLACKLIST=migrations,jobs,failed_jobs,sessions,cache
```

## Configuration

### Connections

Specify which database connections should use replication routing:

```php
'connections' => env('DB_REPLICATION_CONNECTIONS', 'mysql,mariadb'),
```

### Lag Threshold

Global safety net that routes all traffic to master when replicas lag:

```php
'lag_threshold' => [
    'enabled' => env('DB_CHECK_REPLICATION_LAG', true),
    'threshold' => env('DB_REPLICATION_LAG_THRESHOLD', 1), // seconds
    'check_interval' => 60, // cache lag checks for 60 seconds
],
```

### Write Fences

Per-user/tenant read-your-writes guarantee:

```php
'write_fences' => [
    'enabled' => env('DB_WRITE_FENCES_ENABLED', false),
    'hot_ttl' => env('DB_WRITE_FENCE_HOT_TTL', 2), // fast path duration
    'fence_ttl' => env('DB_WRITE_FENCE_TTL', 600), // fence storage duration
    'track_user' => env('DB_WRITE_FENCE_TRACK_USER', true),
    'track_tenant' => env('DB_WRITE_FENCE_TRACK_TENANT', true),
    
    // Only track specific tables (empty = all tables)
    'table_whitelist' => [],
    
    // Ignore these tables
    'table_blacklist' => ['migrations', 'jobs', 'failed_jobs', 'sessions', 'cache'],
],
```

## Deployment Strategy

### Phase 1: Lag Threshold Only (Conservative)

```env
DB_WRITE_FENCES_ENABLED=false
DB_CHECK_REPLICATION_LAG=true
```

**What happens**: Simple lag-based routing. If any replica lags > 1s, route all reads to master.

### Phase 2: Enable Write Fences (Optimal)

```env
DB_WRITE_FENCES_ENABLED=true
DB_CHECK_REPLICATION_LAG=true
```

**What happens**: Users read their own writes immediately, while other users benefit from replicas.

### Phase 3: Fine-Tune (Performance)

```env
DB_WRITE_FENCE_TABLE_WHITELIST=products,listings,orders,inventory
```

**What happens**: Only critical tables trigger write fences, reducing Redis operations.

## Performance Characteristics

### Write Overhead
- **~1.6ms** per write (GTID query + Redis ops)
- Happens asynchronously via event listener

### Read Overhead
- **~0.5ms** for hot flag check (Redis GET)
- **~1.6ms** for fence check (Redis GET + GTID_SUBSET query)
- **No overhead** when fences expired or disabled

### Redis Load (Example: 10k users, 1k writes/min, 100k reads/min)
- **~6,500 ops/sec**
- Mostly GET operations (fast)

## Monitoring

### Key Metrics to Track

1. **Hot routing rate**: % of reads hitting master via hot flags
2. **Fence hit rate**: % of reads with active fences
3. **Lag threshold fallback rate**: % of reads routed via lag detection
4. **Master vs replica distribution**: Overall read traffic split

### Laravel Telescope

Writes are logged with context:

```php
Log::info("Fence routing to master for mysql", [
    'user_id' => 123,
    'tenant_id' => 45,
    'fence' => 'uuid:1-100',
    'reason' => 'replica_behind_fence',
]);
```

## Rollback Strategy

### Level 1: Disable Write Fences
```env
DB_WRITE_FENCES_ENABLED=false
```
Falls back to lag threshold only.

### Level 2: Disable All Routing
```env
DB_REPLICATION_ROUTING_ENABLED=false
```
Uses Laravel's default read/write behavior.

### Level 3: Remove Middleware
Comment out `ResolveReplicationRouting::class` in `bootstrap/app.php`.

## Requirements

- **PHP**: 8.2+
- **Laravel**: 10.x or 11.x
- **Database**: MySQL 5.7+/MariaDB 10.0+ or PostgreSQL 10+
- **Redis**: Any version
- **Replication**: GTID-based (MySQL/MariaDB) or streaming (PostgreSQL)

## Testing

The package includes comprehensive test coverage:

```bash
# Run package tests
cd modules/replication-routing
vendor/bin/pest

# Run with coverage
vendor/bin/pest --coverage
```

## Troubleshooting

### Writes Not Being Fenced

**Check:**
1. Is `DB_WRITE_FENCES_ENABLED=true`?
2. Is middleware registered **after** authentication?
3. Is the table in the whitelist (if using whitelist)?
4. Is the table NOT in the blacklist?

### Users Still See Stale Data

**Check:**
1. Replication lag exceeds `hot_ttl` (increase it)
2. GTID not enabled on MySQL
3. Redis cache not working
4. Fence expired (increase `fence_ttl`)

### High Master Load

**Possible causes:**
1. Lag threshold too aggressive (increase threshold or interval)
2. Hot TTL too long (decrease it)
3. Too many tables in whitelist (be selective)

## Credits

Developed by MyListerHub team based on the Medium article:
[Beating Replication Lag: Read-Your-Writes Guarantees](https://medium.com/@mohamadshahkhajeh/beating-replication-lag-read-your-writes-guarantees-for-replica-heavy-stacks-9e311e75d8cc)

## License

MIT License - see LICENSE file for details
