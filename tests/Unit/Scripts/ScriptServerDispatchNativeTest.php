<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

test('native Script Server admits configured scripts and rejects unowned dispatches', function (string $layout) {
    $root = dirname(__DIR__, 3);
    $temporary = sys_get_temp_dir() . '/script-dispatch-' . bin2hex(random_bytes(8));
    foreach (['include','scripts','relocated','scripts_evil','plugins','lib'] as $directory) {
        mkdir($temporary . '/' . $directory, 0700, true);
    }
    $copy = $temporary . '/script_server.php';
    expect(copy($root . '/script_server.php', $copy))->toBeTrue();
    expect(hash_file('sha256', $copy))->toBe(hash_file('sha256', $root . '/script_server.php'));
    $scripts = $layout === 'configured' ? $temporary . '/relocated' : $temporary . '/scripts';
    if ($layout === 'symlink') {
        rmdir($temporary . '/scripts');
        expect(symlink(realpath($temporary . '/relocated'), $temporary . '/scripts'))->toBeTrue();
        $this->assertSame(realpath($temporary . '/relocated'), realpath($temporary . '/scripts'), 'The owned script-root link must resolve before dispatch.');
        $scripts = $temporary . '/scripts';
    }
    file_put_contents($scripts . '/helper.php', '<?php function dispatch_helper(){return "unexpected-helper";}');
    file_put_contents($scripts . '/Selected.php', '<?php require_once __DIR__."/helper.php"; function dispatch_selected(){return "selected-ok";}');
    file_put_contents($scripts . '/other.php', '<?php function dispatch_other(){return "other-ok";}');
    foreach (['scripts_evil','plugins','lib'] as $directory) {
        file_put_contents($temporary . '/' . $directory . '/other.php', '<?php function unrelated_dispatch(){return "unexpected-other";}');
    }
    $configured = $layout === 'configured' ? $scripts : $temporary . '/missing-configured-root';
    if ($layout === 'missing') {
        unlink($scripts . '/Selected.php');
        unlink($scripts . '/helper.php');
        unlink($scripts . '/other.php');
        rmdir($scripts);
    }
    $bootstrap = '<?php $config=' . var_export(['cacti_server_os' => PHP_OS_FAMILY === 'Windows' ? 'win32' : 'unix','base_path' => $temporary,'scripts_path' => $configured], true) . ';' . <<<'BOOT'
define('POLLER_VERBOSITY_DEBUG',5); define('POLLER_VERBOSITY_HIGH',3); define('POLLER_VERBOSITY_MEDIUM',2);
function read_config_option($name){return 300;}
function cacti_log($message,...$args){file_put_contents(__DIR__.'/logs', $message."\n", FILE_APPEND);}
function db_close(){file_put_contents(__DIR__.'/closed','yes');}
BOOT;
    file_put_contents($temporary . '/include/cli_check.php', $bootstrap);
    $command = [PHP_BINARY, '-d', 'pcov.directory=/'];
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    if ($coverage !== null) {
        file_put_contents($temporary . '/coverage.php', '<?php define("RRD_TEST_COVERAGE_DIRECTORY",__DIR__); define("RRD_TEST_CLI_COVERAGE_COPY",'.var_export($copy, true).'); define("RRD_TEST_CLI_COVERAGE_SOURCE",'.var_export($root.'/script_server.php', true).'); require '.var_export($root.'/tests/fixtures/rrd-process-coverage.php', true).';');
        $command = array_merge($command, ['-d','auto_prepend_file='.$temporary.'/coverage.php']);
    }
    $command[] = $copy;
    $requests = [
        $scripts.'/Selected.php dispatch_selected',
        $scripts.'/Selected.php dispatch_helper',
        $scripts.'/Selected.php strlen ignored',
        $scripts.'/other.php dispatch_selected',
        $scripts.'/other.php dispatch_other',
        $scripts.'/absent.php dispatch_selected',
        $temporary.'/scripts_evil/other.php unrelated_dispatch',
        $temporary.'/plugins/other.php unrelated_dispatch',
        $temporary.'/lib/other.php unrelated_dispatch',
        $scripts.'/../lib/other.php unrelated_dispatch',
        $scripts.'/nested dispatch_selected',
    ];
    if ($layout !== 'missing') {
        mkdir($scripts.'/nested', 0700);
    }
    $process = null;
    try {
        $process = proc_open($command, [0 => ['pipe','r'],1 => ['pipe','w'],2 => ['pipe','w']], $pipes);
        expect(is_resource($process))->toBeTrue();
        $input = implode("\n", $requests)."\nquit\n";
        expect(fwrite($pipes[0], $input))->toBe(strlen($input));
        fclose($pipes[0]);
        stream_set_timeout($pipes[1], 10);
        $output = stream_get_contents($pipes[1]);
        $metadata = stream_get_meta_data($pipes[1]);
        fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        if ($metadata['timed_out']) {
            proc_terminate($process);
        }
        expect(proc_close($process))->toBe(0, $error.$output);
        $process = null;
        expect($metadata['timed_out'])->toBeFalse()->and($error)->toBe('');
        $lines = explode("\n", trim($output));
        expect(array_shift($lines))->toBe('PHP Script Server has Started - Parent is cmd');
        if ($layout === 'missing') {
            expect(array_pop($lines))->toBe('PHP Script Server Shutdown request received, exiting');
        }
        $expected = ['selected-ok','U','U','U','other-ok','U','U','U','U','U','U'];
        $logs = file_get_contents($temporary.'/include/logs');
        $this->assertSame($layout === 'missing' ? array_fill(0, count($requests), 'U') : $expected, $lines, $logs);
        expect($logs)->toContain('could not be resolved. Rejected.');
        if ($layout !== 'missing') {
            expect($logs)->toContain('was not defined by script file')->toContain('Refusing to dispatch PHP internal function')
                ->toContain('resolves outside scripts directory. Rejected.')->toContain('PHP Script File to be included, does not exist');
        }
        expect(file_get_contents($temporary.'/include/closed'))->toBe('yes');
        if ($coverage !== null) {
            $reports = glob($temporary.'/*.coverage');
            expect($reports)->toHaveCount(1);
            $coverage->merge(unserialize(file_get_contents($reports[0])));
        }
    } finally {
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
        if ($layout === 'symlink') {
            // Remove the owned directory link before its target can become dangling.
            PHP_OS_FAMILY === 'Windows' ? rmdir($temporary.'/scripts') : unlink($temporary.'/scripts');
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            // Windows directory symlink entries are removed with rmdir; the iterator does not follow them.
            $entry->isDir() && (PHP_OS_FAMILY === 'Windows' || !$entry->isLink())
                ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($temporary);
    }
})->with(['default','configured','symlink','missing']);
