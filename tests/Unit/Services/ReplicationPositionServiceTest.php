<?php

use MyListerHub\ReplicationRouting\Services\ReplicationPositionService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->service = new ReplicationPositionService();
});

test('it returns mysql gtid position', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('mysql');
    $connection->shouldReceive('selectOne')
        ->with('SELECT @@global.gtid_executed AS gtid')
        ->andReturn((object) ['gtid' => 'uuid:1-5']);

    DB::shouldReceive('connection')->with('mysql')->andReturn($connection);

    $position = $this->service->getCurrentPosition('mysql');

    expect($position)->toBe('uuid:1-5');
});

test('it returns null when gtid is empty', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('mysql');
    $connection->shouldReceive('selectOne')
        ->with('SELECT @@global.gtid_executed AS gtid')
        ->andReturn((object) ['gtid' => '']);

    DB::shouldReceive('connection')->with('mysql')->andReturn($connection);

    $position = $this->service->getCurrentPosition('mysql');

    expect($position)->toBeNull();
});

test('it returns null when query fails', function () {
    Log::shouldReceive('warning')->once();

    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('mysql');
    $connection->shouldReceive('selectOne')
        ->andThrow(new Exception('Query failed'));

    DB::shouldReceive('connection')->with('mysql')->andReturn($connection);

    $position = $this->service->getCurrentPosition('mysql');

    expect($position)->toBeNull();
});

test('it checks mysql gtid subset correctly', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('mysql');
    $connection->shouldReceive('selectOne')
        ->with('SELECT GTID_SUBSET(?, @@global.gtid_executed) AS caught_up', ['uuid:1-3'])
        ->andReturn((object) ['caught_up' => 1]);

    DB::shouldReceive('connection')->with('mysql')->andReturn($connection);

    $hasReached = $this->service->hasReachedPosition('mysql', 'uuid:1-3');

    expect($hasReached)->toBeTrue();
});

test('it returns false when replica behind', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('mysql');
    $connection->shouldReceive('selectOne')
        ->with('SELECT GTID_SUBSET(?, @@global.gtid_executed) AS caught_up', ['uuid:1-10'])
        ->andReturn((object) ['caught_up' => 0]);

    DB::shouldReceive('connection')->with('mysql')->andReturn($connection);

    $hasReached = $this->service->hasReachedPosition('mysql', 'uuid:1-10');

    expect($hasReached)->toBeFalse();
});

test('it returns null for unsupported driver', function () {
    Log::shouldReceive('warning')->once();

    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('sqlite');

    DB::shouldReceive('connection')->with('sqlite')->andReturn($connection);

    $position = $this->service->getCurrentPosition('sqlite');

    expect($position)->toBeNull();
});

test('it handles postgresql lsn', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('pgsql');
    $connection->shouldReceive('selectOne')
        ->with('SELECT pg_current_wal_lsn() AS lsn')
        ->andReturn((object) ['lsn' => '0/3000000']);

    DB::shouldReceive('connection')->with('pgsql')->andReturn($connection);

    $position = $this->service->getCurrentPosition('pgsql');

    expect($position)->toBe('0/3000000');
});

test('it compares postgresql lsn correctly', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('pgsql');
    $connection->shouldReceive('selectOne')
        ->with('SELECT pg_last_wal_replay_lsn() AS lsn')
        ->andReturn((object) ['lsn' => '0/4000000']);

    DB::shouldReceive('connection')->with('pgsql')->andReturn($connection);

    $hasReached = $this->service->hasReachedPosition('pgsql', '0/3000000');

    expect($hasReached)->toBeTrue();
});

test('it returns false when postgresql replica behind', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('pgsql');
    $connection->shouldReceive('selectOne')
        ->with('SELECT pg_last_wal_replay_lsn() AS lsn')
        ->andReturn((object) ['lsn' => '0/2000000']);

    DB::shouldReceive('connection')->with('pgsql')->andReturn($connection);

    $hasReached = $this->service->hasReachedPosition('pgsql', '0/3000000');

    expect($hasReached)->toBeFalse();
});
