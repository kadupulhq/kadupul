<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('configured runtime whitelist requires a validated method', function ($case, $expected) {
    $root = dirname(__DIR__, 2);
    $process = proc_open(array(PHP_BINARY, '-d', 'error_reporting=E_ALL',
        $root . '/tests/fixtures/input-whitelist-runtime.php', $root, $case),
        array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    expect(is_resource($process))->toBeTrue();
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0);
    expect($stderr)->toBe('');
    expect(json_decode($stdout, true, 512, JSON_THROW_ON_ERROR))->toBe($expected);
})->with(array(
    'unset' => array('unset', array('first' => true, 'repeat' => true, 'empty-command' => true, 'unknown' => true)),
    'valid' => array('valid', array('first' => true, 'repeat' => true, 'empty-command' => true, 'unknown' => false)),
    'empty' => array('empty', array('first' => false, 'repeat' => false, 'empty-command' => true, 'unknown' => false)),
    'missing entry' => array('missing-entry', array('first' => false, 'repeat' => false, 'empty-command' => true, 'unknown' => false)),
    'changed command' => array('changed-command', array('first' => false, 'repeat' => false, 'empty-command' => true, 'unknown' => false)),
    'wrong type' => array('wrong-type', array('first' => false, 'repeat' => false, 'empty-command' => true, 'unknown' => false)),
    'malformed' => array('malformed', array('first' => false, 'repeat' => false, 'empty-command' => false, 'unknown' => false)),
    'null' => array('null', array('first' => false, 'repeat' => false, 'empty-command' => false, 'unknown' => false)),
    'boolean' => array('boolean', array('first' => false, 'repeat' => false, 'empty-command' => false, 'unknown' => false)),
    'scalar' => array('scalar', array('first' => false, 'repeat' => false, 'empty-command' => false, 'unknown' => false)),
    'missing file' => array('missing-file', array('first' => false, 'repeat' => false, 'empty-command' => false, 'unknown' => false)),
    'invalid path' => array('invalid-path', array('first' => false, 'repeat' => false, 'empty-command' => false, 'unknown' => false)),
    'directory' => array('directory', array('first' => false, 'repeat' => false, 'empty-command' => false, 'unknown' => false))
));
