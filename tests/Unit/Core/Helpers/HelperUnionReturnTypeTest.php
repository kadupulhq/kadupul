<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

// Control failure paths at the I/O boundary; execute the production bodies intact.
eval(<<<'ADAPTERS'
namespace KadupulUnionTests;
function function_exists($name) {
    return $name === 'posix_geteuid' ? $GLOBALS['union_state']['uid_mode'] === 'posix' : \function_exists($name);
}
function posix_geteuid() { return 4242; }
function tempnam($directory, $prefix) {
    return $GLOBALS['union_state']['uid_mode'] === 'temp-failure' ? false : '/fixture/process-owner';
}
function fileowner($path) { return $GLOBALS['union_state']['uid_mode'] === 'owner-failure' ? false : 77; }
function unlink($path) { $GLOBALS['union_state']['unlinked'][] = $path; return true; }
function sys_get_temp_dir() {
    return $GLOBALS['union_state']['workspace_mode'] === 'missing-root'
        ? $GLOBALS['union_state']['dir'] . '/missing' : \sys_get_temp_dir();
}
function rrd_maintenance_directory_is_trusted($path) {
    return $GLOBALS['union_state']['workspace_mode'] !== 'untrusted';
}
function rrd_maintenance_filesystem() {
    return new class {
        public function mkdir($directory, $permissions) {
            \mkdir($directory, $permissions);
            $GLOBALS['union_state']['workspaces'][] = $directory;
        }
        public function remove($directory) { \rmdir($directory); }
    };
}
function rrd_maintenance_acquire($exclusive, $wait, $timeout, &$busy) {
    $GLOBALS['union_state']['attempts']++;
    if ($GLOBALS['union_state']['attempts'] === $GLOBALS['union_state']['fail_lock']) {
        $busy = true;
        return false;
    }
    $handle = \fopen('php://memory', 'r+');
    $GLOBALS['union_state']['handles'][] = $handle;
    return $handle;
}
function rrd_maintenance_release($locks) {
    foreach ($locks as $lock) {
        \fclose($lock);
        $GLOBALS['union_state']['released']++;
    }
}
function proc_open($command, $descriptors, &$pipes) {
    return $command === 'launch-failure' ? false : \proc_open($command, $descriptors, $pipes);
}
ADAPTERS);

$unionFunctions = [
    'rrd_maintenance_workspace' => ['lib/rrd_maintenance.php', ['false', 'string']],
    'rrd_maintenance_acquire_paths' => ['lib/rrd_maintenance.php', ['array', 'false']],
    'csp_report_process_uid' => ['lib/csp_report_endpoint.php', ['false', 'int']],
    'api_clone_get_unique_filename' => ['lib/api_device.php', ['false', 'string']],
    'exec_with_timeout' => ['lib/poller.php', ['false', 'null', 'string']],
];
$unionSources = [];
foreach ($unionFunctions as $name => [$file, $types]) {
    // These definitions are immutable within this file's setup. Mutable I/O
    // and global state still get fresh fixtures in beforeEach/afterEach.
    $unionSources[$file] ??= file_get_contents(dirname(__DIR__, 4) . '/' . $file);
    if (!is_string($unionSources[$file])) {
        throw new RuntimeException('Unable to read the actual helper source: ' . $file);
    }
    eval('namespace KadupulUnionTests; ' . test_php_function_source($unionSources[$file], $name));
}

beforeEach(function () {
    $this->unionHadConfig = array_key_exists('config', $GLOBALS);
    $this->unionConfig = $GLOBALS['config'] ?? null;
    $this->unionDir = sys_get_temp_dir() . '/kadupul-union-test-' . bin2hex(random_bytes(8));
    mkdir($this->unionDir, 0700);
    mkdir($this->unionDir . '/rra', 0700);
    $GLOBALS['config'] = ['rra_path' => $this->unionDir . '/rra'];
    $GLOBALS['union_state'] = [
        'dir' => $this->unionDir, 'uid_mode' => 'posix', 'workspace_mode' => 'trusted',
        'unlinked' => [], 'workspaces' => [], 'handles' => [], 'attempts' => 0,
        'fail_lock' => 0, 'released' => 0,
    ];
});

afterEach(function () {
    foreach ($GLOBALS['union_state']['handles'] as $handle) {
        if (is_resource($handle)) {
            fclose($handle);
        }
    }
    foreach ($GLOBALS['union_state']['workspaces'] as $directory) {
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->unionDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $path) {
        $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname());
    }
    rmdir($this->unionDir);
    if ($this->unionHadConfig) {
        $GLOBALS['config'] = $this->unionConfig;
    } else {
        unset($GLOBALS['config']);
    }
    unset($GLOBALS['union_state']);
});

test('helper return types describe every result without broadening to bool', function ($name, $expected) {
    $type = (new ReflectionFunction('KadupulUnionTests\\' . $name))->getReturnType();
    expect($type)->toBeInstanceOf(ReflectionUnionType::class);
    $actual = array_map(static fn(ReflectionNamedType $member): string => $member->getName(), $type->getTypes());
    sort($actual);
    expect($actual)->toBe($expected);
})->with(array_map(static fn($name, $entry): array => [$name, $entry[1]], array_keys($unionFunctions), array_values($unionFunctions)));

test('unique filenames preserve their existing spelling and collision sequence', function () {
    $source = $this->unionDir . '/template.xml';
    $first = $this->unionDir . '/template._01.xml';
    expect(KadupulUnionTests\api_clone_get_unique_filename($source))->toBe($first);
    file_put_contents($first, 'existing');
    expect(KadupulUnionTests\api_clone_get_unique_filename($source))->toBe($this->unionDir . '/template._02.xml');
});

test('unique filename exhaustion remains false and leaves files intact', function () {
    for ($i = 1; $i < 20; $i++) {
        file_put_contents($this->unionDir . '/template._' . sprintf('%02d', $i) . '.xml', 'existing');
    }
    expect(KadupulUnionTests\api_clone_get_unique_filename($this->unionDir . '/template.xml'))->toBeFalse();
    expect(file_get_contents($this->unionDir . '/template._19.xml'))->toBe('existing');
});

test('process owner retains native fallback and failure results', function ($mode, $expected, $cleanup) {
    $GLOBALS['union_state']['uid_mode'] = $mode;
    expect(KadupulUnionTests\csp_report_process_uid())->toBe($expected);
    expect($GLOBALS['union_state']['unlinked'])->toBe($cleanup ? ['/fixture/process-owner'] : []);
})->with([
    ['posix', 4242, false], ['fallback', 77, true],
    ['temp-failure', false, false], ['owner-failure', false, true],
]);

test('private workspace success returns its path and preserves permissions', function () {
    $path = KadupulUnionTests\rrd_maintenance_workspace();
    expect($path)->toBeString()->and(is_dir($path))->toBeTrue()->and(fileperms($path) & 0777)->toBe(0700);
});

test('workspace failure remains false and removes an untrusted directory', function ($mode) {
    $GLOBALS['union_state']['workspace_mode'] = $mode;
    expect(KadupulUnionTests\rrd_maintenance_workspace())->toBeFalse();
    foreach ($GLOBALS['union_state']['workspaces'] as $directory) {
        expect(is_dir($directory))->toBeFalse();
    }
})->with(['missing-root', 'untrusted']);

test('path leases return a collection and restore the caller storage configuration', function () {
    mkdir($this->unionDir . '/external', 0700);
    $before = $GLOBALS['config'];
    $busy = null;
    $locks = KadupulUnionTests\rrd_maintenance_acquire_paths([$this->unionDir . '/external/new.rrd'], 0, $busy);
    expect($locks)->toBeArray()->not->toBe([])->and($busy)->toBeFalse()->and($GLOBALS['config'])->toBe($before);
    foreach ($locks as $lock) {
        expect(is_resource($lock))->toBeTrue();
    }
});

test('lease failures release partial collections and restore configuration', function () {
    mkdir($this->unionDir . '/external', 0700);
    $GLOBALS['union_state']['fail_lock'] = 2;
    $before = $GLOBALS['config'];
    $busy = false;
    expect(KadupulUnionTests\rrd_maintenance_acquire_paths([$this->unionDir . '/external/new.rrd'], 0, $busy))->toBeFalse();
    expect($busy)->toBeTrue()->and($GLOBALS['union_state']['released'])->toBe(1)->and($GLOBALS['config'])->toBe($before);
});

test('invalid lease paths fail before acquiring locks', function () {
    expect(KadupulUnionTests\rrd_maintenance_acquire_paths([$this->unionDir . '/missing/new.rrd']))->toBeFalse();
    expect($GLOBALS['union_state']['attempts'])->toBe(0);
});

test('command output preserves the last line exit status and output reference', function () {
    $output = ['original'];
    $status = 99;
    $result = KadupulUnionTests\exec_with_timeout([PHP_BINARY, '-r', 'echo "first\nlast"; exit(7);'], $output, $status);
    expect($result)->toBe('last')->and($output)->toBe(['first', 'last'])->and($status)->toBe(7);
});

test('a command without output preserves the existing null result', function () {
    $output = ['original'];
    $status = 99;
    expect(KadupulUnionTests\exec_with_timeout([PHP_BINARY, '-r', 'exit(3);'], $output, $status))->toBeNull();
    expect($output)->toBe(['original'])->and($status)->toBe(3);
});

test('command launch failure remains false and preserves caller references', function () {
    $output = ['original'];
    $status = 99;
    expect(KadupulUnionTests\exec_with_timeout('launch-failure', $output, $status))->toBeFalse();
    expect($output)->toBe(['original'])->and($status)->toBe(99);
});
