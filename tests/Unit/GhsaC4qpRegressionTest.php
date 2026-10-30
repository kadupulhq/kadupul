<?php

declare(strict_types=1);
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

$functionsSource = file_get_contents(__DIR__ . '/../../lib/functions.php');

test('GHSA-c4qp-j9r9-fq24: test-script path expansion shell-escapes data_input values', function (): void {
    $root = dirname(__DIR__, 2);
    $payload = "literal \$(printf EXPANDED); ' \" & | > <";
    $program = <<<'PHP'
$root = $argv[1];
$config = array('is_web' => false, 'cacti_server_os' => 'unix', 'base_path' => '/fixture',
    'config_options_array' => array('path_php_binary' => '/usr/bin/php', 'path_snmpget' => '/usr/bin/snmpget'));
require $root . '/include/global_constants.php';
require $root . '/lib/functions.php';
require $root . '/tests/Fixtures/data-input-substitution-native.php';
$command = data_input_command('test', 'collector <empty> <arg1> tail', array('empty' => '', 'arg1' => $argv[2]), array('id' => 9));
echo json_encode(data_input_shell_words($command), JSON_THROW_ON_ERROR);
PHP;
    $process = proc_open(
        array(PHP_BINARY, '-r', $program, $root, $payload),
        array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes
    );
    expect(is_resource($process))->toBeTrue();
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0)->and($error)->toBe('');
    expect(json_decode($output, true, 512, JSON_THROW_ON_ERROR))->toBe(array(
        'status' => 0, 'words' => array('collector', $payload, 'tail'), 'err' => '',
    ));
});

test('GHSA-c4qp-j9r9-fq24: get_full_test_script_path does not wrap raw field values in manual quotes', function () use ($functionsSource) {
    expect($functionsSource)->not->toContain("\$value = \"'\" . \$item['value'] . \"'\";");
});
