<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';

eval(<<<'ADAPTERS'
namespace KadupulUnionTests;
function proc_open($command, $descriptors, &$pipes) {
    return $command === 'launch-failure' ? false : \proc_open($command, $descriptors, $pipes);
}
ADAPTERS);

$unionFunctions = [
    'api_clone_get_unique_filename' => ['lib/api_device.php', ['false', 'string']],
    'exec_with_timeout' => ['lib/poller.php', ['false', 'null', 'string']],
];
foreach ($unionFunctions as $name => [$file, $types]) {
    eval('namespace KadupulUnionTests; ' . test_php_function_source(file_get_contents(dirname(__DIR__, 2) . '/' . $file), $name));
}

beforeEach(function () {
    $this->unionDir = sys_get_temp_dir() . '/kadupul-union-test-' . bin2hex(random_bytes(8));
    mkdir($this->unionDir, 0700);
});

afterEach(function () {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->unionDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $path) {
        $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname());
    }
    rmdir($this->unionDir);
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
