<?php

use MyListerHub\ReplicationRouting\Services\ReplicationLagChecker;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

test('it returns lag using modern syntax', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('selectOne')
        ->with('SHOW REPLICA STATUS')
        ->andReturn((object) ['Seconds_Behind_Master' => 5]);

    DB::shouldReceive('connection')
        ->with('mysql')
        ->andReturn($connection);

    $checker = new ReplicationLagChecker();
    $lag = $checker->checkLag('mysql');

    expect($lag)->toBe(5);
});

test('it returns lag using legacy syntax', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('selectOne')
        ->with('SHOW REPLICA STATUS')
        ->andThrow(new Exception('Syntax error'));

    $connection->shouldReceive('selectOne')
        ->with('SHOW SLAVE STATUS')
        ->andReturn((object) ['Seconds_Behind_Master' => 3]);

    DB::shouldReceive('connection')
        ->with('mysql')
        ->andReturn($connection);

    $checker = new ReplicationLagChecker();
    $lag = $checker->checkLag('mysql');

    expect($lag)->toBe(3);
});

test('it returns null when status is empty', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('selectOne')
        ->with('SHOW REPLICA STATUS')
        ->andReturn(null);

    $connection->shouldReceive('selectOne')
        ->with('SHOW SLAVE STATUS')
        ->andReturn(null);

    DB::shouldReceive('connection')
        ->with('mysql')
        ->andReturn($connection);

    $checker = new ReplicationLagChecker();
    $lag = $checker->checkLag('mysql');

    expect($lag)->toBeNull();
});

test('it returns null when seconds behind master is null', function () {
    Log::shouldReceive('warning')->once();

    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('selectOne')
        ->with('SHOW REPLICA STATUS')
        ->andReturn((object) ['Seconds_Behind_Master' => null]);

    DB::shouldReceive('connection')
        ->with('mysql')
        ->andReturn($connection);

    $checker = new ReplicationLagChecker();
    $lag = $checker->checkLag('mysql');

    expect($lag)->toBeNull();
});

test('it handles case insensitive keys', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('selectOne')
        ->with('SHOW REPLICA STATUS')
        ->andReturn((object) ['seconds_behind_master' => 10]);

    DB::shouldReceive('connection')
        ->with('mysql')
        ->andReturn($connection);

    $checker = new ReplicationLagChecker();
    $lag = $checker->checkLag('mysql');

    expect($lag)->toBe(10);
});
