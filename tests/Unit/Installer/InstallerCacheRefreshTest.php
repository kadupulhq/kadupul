<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// cache:clear holds the exclusive cache lock that web requests wait on for at
// most CacheDirectoryLock::SHARED_TIMEOUT_SECONDS. Compiling the translation
// catalogues of every shipped locale inside it outlasted that bound, and the
// web installer's status request answered 503. The refresh therefore builds
// the container under the lock and warms the optional caches afterwards.
test('the installer cache refresh compiles optional caches outside the locked cache:clear', function (string $failing, bool $refreshed, array $expected) {
    $root = dirname(__DIR__, 3);
    $dir = sys_get_temp_dir() . '/installer-cache-refresh-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    mkdir($dir . '/bin', 0700);
    file_put_contents($dir . '/bin/console', '<?php file_put_contents(dirname(__DIR__)."/calls",json_encode([array_slice($argv,1),getenv("APP_ENV"),getenv("APP_DEBUG")])."\n",FILE_APPEND);exit($argv[1]===' . var_export($failing, true) . '?1:0);');
    $script = '<?php function __($message, ...$args) { return $args ? vsprintf($message, $args) : $message; }'
        . '$config = array("base_path" => __DIR__);'
        . 'require ' . var_export($root . '/include/vendor/autoload.php', true) . ';'
        . 'require ' . var_export($root . '/lib/installer.php', true) . ';'
        . 'try { Installer::refreshSymfonyCache(); echo "refreshed"; } catch (RuntimeException $e) { echo $e->getMessage(); }';
    file_put_contents($dir . '/refresh.php', $script);
    try {
        $process = proc_open([PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'auto_append_file=', $dir . '/refresh.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir);
        expect(is_resource($process))->toBeTrue();
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0, $errors . $output)->and($errors)->toBe('');
        $calls = array_map(static fn(string $line): array => json_decode($line, true, 8, JSON_THROW_ON_ERROR), file($dir . '/calls', FILE_IGNORE_NEW_LINES));
        expect(array_column($calls, 0))->toBe($expected);
        foreach ($calls as $call) {
            expect(array_slice($call, 1))->toBe(['prod', '0']);
        }
        if ($refreshed) {
            expect($output)->toBe('refreshed');
        } else {
            expect($output)->toStartWith('Unable to refresh the Symfony production cache.');
        }
    } finally {
        foreach (['/bin/console', '/refresh.php', '/calls'] as $file) {
            if (is_file($dir . $file)) {
                unlink($dir . $file);
            }
        }
        rmdir($dir . '/bin');
        rmdir($dir);
    }
})->with([
    'both steps run in order' => ['', true, [
        ['cache:clear', '--no-optional-warmers', '--env=prod', '--no-debug', '--no-interaction'],
        ['cache:warmup', '--env=prod', '--no-debug', '--no-interaction'],
    ]],
    'a failed clear stops before the warmup' => ['cache:clear', false, [
        ['cache:clear', '--no-optional-warmers', '--env=prod', '--no-debug', '--no-interaction'],
    ]],
    'a failed warmup fails the refresh' => ['cache:warmup', false, [
        ['cache:clear', '--no-optional-warmers', '--env=prod', '--no-debug', '--no-interaction'],
        ['cache:warmup', '--env=prod', '--no-debug', '--no-interaction'],
    ]],
]);
