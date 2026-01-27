<?php

use MyListerHub\ReplicationRouting\Http\Middleware\ResolveReplicationRouting;
use MyListerHub\ReplicationRouting\Services\WriteFenceManager;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Config::set('replication-routing.enabled', true);
    Config::set('replication-routing.connections', ['mysql']);
});

test('it uses write connection when fence requires it', function () {
    Config::set('replication-routing.write_fences.enabled', true);
    Config::set('replication-routing.lag_threshold.enabled', false);

    $fenceManager = mock(WriteFenceManager::class, function ($mock) {
        $mock->shouldReceive('shouldUseWriteConnection')
            ->with('mysql', null, null)
            ->andReturn(true);
    });

    $connection = mock(Connection::class, function ($mock) {
        $mock->shouldReceive('useWriteConnectionWhenReading')
            ->with(true)
            ->once();
    });

    DB::shouldReceive('connection')
        ->with('mysql')
        ->andReturn($connection);

    $middleware = new ResolveReplicationRouting($fenceManager);
    $request = Request::create('/test', 'GET');

    $middleware->handle($request, fn ($req) => response('OK'));
});

test('it uses write connection when lag threshold exceeded', function () {
    Config::set('replication-routing.write_fences.enabled', false);
    Config::set('replication-routing.lag_threshold.enabled', true);

    $fenceManager = mock(WriteFenceManager::class);

    Cache::shouldReceive('get')
        ->with('db.replication.lag.mysql')
        ->andReturn(['is_lagging' => true, 'seconds_behind' => 5]);

    $connection = mock(Connection::class, function ($mock) {
        $mock->shouldReceive('useWriteConnectionWhenReading')
            ->with(true)
            ->once();
    });

    DB::shouldReceive('connection')
        ->with('mysql')
        ->andReturn($connection);

    $middleware = new ResolveReplicationRouting($fenceManager);
    $request = Request::create('/test', 'GET');

    $middleware->handle($request, fn ($req) => response('OK'));
});

test('it uses replica when no fence and no lag', function () {
    Config::set('replication-routing.write_fences.enabled', true);
    Config::set('replication-routing.lag_threshold.enabled', true);

    $fenceManager = mock(WriteFenceManager::class, function ($mock) {
        $mock->shouldReceive('shouldUseWriteConnection')
            ->with('mysql', null, null)
            ->andReturn(false);
    });

    Cache::shouldReceive('get')
        ->with('db.replication.lag.mysql')
        ->andReturn(['is_lagging' => false, 'seconds_behind' => 0]);

    $connection = mock(Connection::class, function ($mock) {
        $mock->shouldReceive('useWriteConnectionWhenReading')
            ->with(false)
            ->once();
    });

    DB::shouldReceive('connection')
        ->with('mysql')
        ->andReturn($connection);

    $middleware = new ResolveReplicationRouting($fenceManager);
    $request = Request::create('/test', 'GET');

    $middleware->handle($request, fn ($req) => response('OK'));
});

test('it prioritizes fence over lag threshold', function () {
    Config::set('replication-routing.write_fences.enabled', true);
    Config::set('replication-routing.lag_threshold.enabled', true);

    $fenceManager = mock(WriteFenceManager::class, function ($mock) {
        $mock->shouldReceive('shouldUseWriteConnection')
            ->with('mysql', null, null)
            ->andReturn(true);
    });

    // Lag threshold should NOT be checked if fence already decided
    Cache::shouldReceive('get')->never();

    $connection = mock(Connection::class, function ($mock) {
        $mock->shouldReceive('useWriteConnectionWhenReading')
            ->with(true)
            ->once();
    });

    DB::shouldReceive('connection')
        ->with('mysql')
        ->andReturn($connection);

    $middleware = new ResolveReplicationRouting($fenceManager);
    $request = Request::create('/test', 'GET');

    $middleware->handle($request, fn ($req) => response('OK'));
});

test('it skips when disabled', function () {
    Config::set('replication-routing.enabled', false);

    $fenceManager = mock(WriteFenceManager::class);

    DB::shouldReceive('connection')->never();

    $middleware = new ResolveReplicationRouting($fenceManager);
    $request = Request::create('/test', 'GET');

    $response = $middleware->handle($request, fn ($req) => response('OK'));

    expect($response->getContent())->toBe('OK');
});
