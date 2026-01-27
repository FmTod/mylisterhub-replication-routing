<?php

use MyListerHub\ReplicationRouting\Providers\ReplicationLagServiceProvider;
use MyListerHub\ReplicationRouting\Services\ReplicationLagChecker;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

test('it skips when disabled', function () {
    Config::set('replication-routing.lag_threshold.enabled', false);

    // Expect no cache interactions
    Cache::shouldReceive('get')->never();
    Cache::shouldReceive('put')->never();

    $provider = new ReplicationLagServiceProvider($this->app);
    $provider->boot();
});

test('it uses write connection when lagging', function () {
    Config::set('replication-routing.lag_threshold.enabled', true);
    Config::set('replication-routing.connections', ['mysql']);
    Config::set('replication-routing.lag_threshold.threshold', 1);

    // Mock Cache to return null (cache miss, force fresh check)
    Cache::shouldReceive('get')
        ->with('db.replication.lag.mysql')
        ->andReturn(null);

    // Expect Cache::put to be called with lag data
    Cache::shouldReceive('put')
        ->withArgs(function ($key, $value, $ttl) {
            return $key === 'db.replication.lag.mysql' 
                && is_array($value)
                && $value['is_lagging'] === true
                && $value['seconds_behind'] === 5;
        })
        ->once();

    // Mock Lag Checker
    $this->mock(ReplicationLagChecker::class, function ($mock) {
        $mock->shouldReceive('checkLag')
            ->with('mysql')
            ->andReturn(5); // 5 seconds lag > 1 second threshold
    });

    // Mock DB connection
    $connection = mock(Connection::class, function ($mock) {
        $mock->shouldReceive('useWriteConnectionWhenReading')
            ->with(true)
            ->once();
    });

    DB::shouldReceive('connection')
        ->with('mysql')
        ->andReturn($connection);

    $provider = new ReplicationLagServiceProvider($this->app);
    $provider->boot();
});

test('it uses cached status', function () {
    Config::set('replication-routing.lag_threshold.enabled', true);
    Config::set('replication-routing.connections', ['mysql']);

    // Mock Cache to return cached lagging status (cache hit)
    Cache::shouldReceive('get')
        ->with('db.replication.lag.mysql')
        ->andReturn([
            'seconds_behind' => 5,
            'checked_at' => now()->timestamp,
            'is_lagging' => true,
        ]);

    // Cache hit - should NOT call put
    Cache::shouldReceive('put')->never();

    // Mock Lag Checker - should NOT be called due to cache hit
    $this->mock(ReplicationLagChecker::class, function ($mock) {
        $mock->shouldReceive('checkLag')->never();
    });

    // Mock DB connection
    $connection = mock(Connection::class, function ($mock) {
        $mock->shouldReceive('useWriteConnectionWhenReading')
            ->with(true)
            ->once();
    });

    DB::shouldReceive('connection')
        ->with('mysql')
        ->andReturn($connection);

    $provider = new ReplicationLagServiceProvider($this->app);
    $provider->boot();
});

test('it does not switch when lag is acceptable', function () {
    Config::set('replication-routing.lag_threshold.enabled', true);
    Config::set('replication-routing.connections', ['mysql']);
    Config::set('replication-routing.lag_threshold.threshold', 5);

    // Mock Cache - cache miss
    Cache::shouldReceive('get')
        ->with('db.replication.lag.mysql')
        ->andReturn(null);
        
    // Expect Cache::put with is_lagging = false
    Cache::shouldReceive('put')
        ->withArgs(function ($key, $value, $ttl) {
            return $key === 'db.replication.lag.mysql' 
                && is_array($value)
                && $value['is_lagging'] === false
                && $value['seconds_behind'] === 1;
        })
        ->once();

    // Mock Lag Checker
    $this->mock(ReplicationLagChecker::class, function ($mock) {
        $mock->shouldReceive('checkLag')
            ->with('mysql')
            ->andReturn(1); // 1 second lag < 5 second threshold
    });

    // Mock DB connection - useWriteConnectionWhenReading should be called with false
    $connection = mock(Connection::class, function ($mock) {
        $mock->shouldReceive('useWriteConnectionWhenReading')
            ->with(false)
            ->once();
    });

    DB::shouldReceive('connection')
        ->with('mysql')
        ->andReturn($connection);

    $provider = new ReplicationLagServiceProvider($this->app);
    $provider->boot();
});
