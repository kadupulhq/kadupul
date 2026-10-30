<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

test('notification descriptions use the recorded MIB without multiplying history', function ($view, $request, $ids, $descriptions, $total) {
    if (!getenv('KADUPUL_TEST_MYSQL_DSN')) {
        $this->markTestSkipped('A disposable MariaDB connection is required.');
    }
    $root = dirname(__DIR__, 4);
    $process = proc_open([PHP_BINARY, $root . '/tests/fixtures/snmp-event-description-native.php', json_encode(['view' => $view, 'request' => $request], JSON_THROW_ON_ERROR)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    expect(is_resource($process))->toBeTrue();
    stream_set_timeout($pipes[1], 15);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0, $errors);
    expect($errors)->toBe('');
    $state = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
    expect(array_column($state['rows'], 'id'))->toBe($ids)
        ->and(array_column($state['rows'], 'description'))->toBe($descriptions)
        ->and($state['total'])->toBe($total);
})->with(function () {
    foreach (['utilities', 'manager'] as $view) {
        yield $view . ' all MIBs' => [$view, ['rows' => '10'], [3, 2, 1], ['Description A', null, 'Description B'], 3];
        yield $view . ' selected MIB event' => [$view, ['severity' => '2', 'rows' => '10'], [1], ['Description B'], 1];
        yield $view . ' missing cache is retained' => [$view, ['severity' => '4'], [2], [null], 1];
        yield $view . ' pagination uses event count' => [$view, ['rows' => '1', 'page' => '2'], [2], [null], 3];
        yield $view . ' empty match' => [$view, ['severity' => '3'], [], [], 0];
    }
});
