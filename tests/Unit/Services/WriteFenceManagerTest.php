<?php

use MyListerHub\ReplicationRouting\Services\ReplicationPositionService;
use MyListerHub\ReplicationRouting\Services\WriteFenceManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->positionService = Mockery::mock(ReplicationPositionService::class);
    $this->manager = new WriteFenceManager($this->positionService);
});

test('it records write fence for user', function () {
    Config::set('replication-routing.write_fences.enabled', true);
    Config::set('replication-routing.write_fences.hot_ttl', 2);
    Config::set('replication-routing.write_fences.fence_ttl', 600);
    Config::set('replication-routing.write_fences.track_user', true);
    Config::set('replication-routing.write_fences.track_tenant', false);

    $this->positionService->shouldReceive('getCurrentPosition')
        ->with('mysql')
        ->andReturn('uuid:1-5');

    Cache::shouldReceive('put')
        ->with('db:hot:user:123:mysql', true, 2)
        ->once();

    Cache::shouldReceive('put')
        ->with('db:fence:user:123:mysql', 'uuid:1-5', 600)
        ->once();

    $this->manager->recordWrite('mysql', 123, null);
});

test('it records write fence for tenant', function () {
    Config::set('replication-routing.write_fences.enabled', true);
    Config::set('replication-routing.write_fences.hot_ttl', 2);
    Config::set('replication-routing.write_fences.fence_ttl', 600);
    Config::set('replication-routing.write_fences.track_user', false);
    Config::set('replication-routing.write_fences.track_tenant', true);

    $this->positionService->shouldReceive('getCurrentPosition')
        ->with('mysql')
        ->andReturn('uuid:1-5');

    Cache::shouldReceive('put')
        ->with('db:hot:tenant:456:mysql', true, 2)
        ->once();

    Cache::shouldReceive('put')
        ->with('db:fence:tenant:456:mysql', 'uuid:1-5', 600)
        ->once();

    $this->manager->recordWrite('mysql', null, 456);
});

test('it records both user and tenant fences', function () {
    Config::set('replication-routing.write_fences.enabled', true);
    Config::set('replication-routing.write_fences.hot_ttl', 2);
    Config::set('replication-routing.write_fences.fence_ttl', 600);
    Config::set('replication-routing.write_fences.track_user', true);
    Config::set('replication-routing.write_fences.track_tenant', true);

    $this->positionService->shouldReceive('getCurrentPosition')
        ->with('mysql')
        ->andReturn('uuid:1-5');

    Cache::shouldReceive('put')->times(4); // 2 hot + 2 fence

    $this->manager->recordWrite('mysql', 123, 456);
});

test('it skips recording when disabled', function () {
    Config::set('replication-routing.write_fences.enabled', false);

    Cache::shouldReceive('put')->never();

    $this->manager->recordWrite('mysql', 123, 456);
});

test('it returns true when hot flag exists', function () {
    Config::set('replication-routing.write_fences.enabled', true);

    Cache::shouldReceive('has')
        ->with('db:hot:user:123:mysql')
        ->andReturn(true);

    Log::shouldReceive('debug')->once();

    $shouldUseWrite = $this->manager->shouldUseWriteConnection('mysql', 123, null);

    expect($shouldUseWrite)->toBeTrue();
});

test('it returns true when replica behind fence', function () {
    Config::set('replication-routing.write_fences.enabled', true);
    Config::set('replication-routing.write_fences.track_user', true);

    Cache::shouldReceive('has')->andReturn(false);
    Cache::shouldReceive('get')
        ->with('db:fence:user:123:mysql')
        ->andReturn('uuid:1-5');

    $this->positionService->shouldReceive('hasReachedPosition')
        ->with('mysql', 'uuid:1-5')
        ->andReturn(false); // Replica has NOT reached fence

    Log::shouldReceive('info')->once();

    $shouldUseWrite = $this->manager->shouldUseWriteConnection('mysql', 123, null);

    expect($shouldUseWrite)->toBeTrue();
});

test('it returns false when replica caught up', function () {
    Config::set('replication-routing.write_fences.enabled', true);
    Config::set('replication-routing.write_fences.track_user', true);

    Cache::shouldReceive('has')->andReturn(false);
    Cache::shouldReceive('get')
        ->with('db:fence:user:123:mysql')
        ->andReturn('uuid:1-5');

    $this->positionService->shouldReceive('hasReachedPosition')
        ->with('mysql', 'uuid:1-5')
        ->andReturn(true); // Replica HAS reached fence

    $shouldUseWrite = $this->manager->shouldUseWriteConnection('mysql', 123, null);

    expect($shouldUseWrite)->toBeFalse();
});

test('it returns false when no fence exists', function () {
    Config::set('replication-routing.write_fences.enabled', true);
    Config::set('replication-routing.write_fences.track_user', true);

    Cache::shouldReceive('has')->andReturn(false);
    Cache::shouldReceive('get')
        ->with('db:fence:user:123:mysql')
        ->andReturn(null);

    $shouldUseWrite = $this->manager->shouldUseWriteConnection('mysql', 123, null);

    expect($shouldUseWrite)->toBeFalse();
});

test('it checks tenant fence when user fence missing', function () {
    Config::set('replication-routing.write_fences.enabled', true);
    Config::set('replication-routing.write_fences.track_user', true);
    Config::set('replication-routing.write_fences.track_tenant', true);

    Cache::shouldReceive('has')->andReturn(false);
    Cache::shouldReceive('get')
        ->with('db:fence:user:123:mysql')
        ->andReturn(null);
    Cache::shouldReceive('get')
        ->with('db:fence:tenant:456:mysql')
        ->andReturn('uuid:1-7');

    $this->positionService->shouldReceive('hasReachedPosition')
        ->with('mysql', 'uuid:1-7')
        ->andReturn(false);

    Log::shouldReceive('info')->once();

    $shouldUseWrite = $this->manager->shouldUseWriteConnection('mysql', 123, 456);

    expect($shouldUseWrite)->toBeTrue();
});

test('it handles errors gracefully', function () {
    Config::set('replication-routing.write_fences.enabled', true);

    Cache::shouldReceive('has')->andThrow(new Exception('Cache error'));

    Log::shouldReceive('warning')->once();

    $shouldUseWrite = $this->manager->shouldUseWriteConnection('mysql', 123, null);

    expect($shouldUseWrite)->toBeFalse(); // Falls back safely
});
