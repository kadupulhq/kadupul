<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('plugin allperms grants existing realms only to the configured administrator', function () {
    $application = dirname(__DIR__, 3);
    $temporary = sys_get_temp_dir() . '/plugin-allperms-' . bin2hex(random_bytes(8));
    $plugin = 'allperms_fixture';
    $pluginDirectory = $temporary . '/plugins/' . $plugin;
    mkdir($pluginDirectory, 0700, true);

    $bootstrap = $temporary . '/bootstrap.php';
    $bootstrapSource = <<<'PHP'
<?php
$config = ['base_path' => getenv('PLUGIN_TEST_BASE_PATH')];
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function api_plugin_installed($plugin) { return true; }
function read_config_option($name) { return getenv('PLUGIN_ADMIN_USER'); }
function db_fetch_row_prepared($sql, $params = []) {
    return getenv('PLUGIN_ADMIN_EXISTS') === '1' ? ['id' => (int) $params[0]] : false;
}
function db_fetch_assoc_prepared($sql, $params = []) {
    if (getenv('PLUGIN_REALMS') === 'failure') { return false; }
    if (getenv('PLUGIN_REALMS') === 'empty') { return []; }
    return array_map(static function ($id) use ($params) { return ['id' => $id, 'plugin' => $params[0]]; }, getenv('PLUGIN_REALMS') === 'two' ? [12, 13] : [$params[0] === 'second_fixture' ? 13 : 12]);
}
function db_execute_prepared($sql, $params = []) {
    if (getenv('PLUGIN_GRANT_FAIL') === '1' || (getenv('PLUGIN_GRANT_FAIL') === 'first' && $params[1] === 112)) {
        return false;
    }
    $state = json_decode(file_get_contents(getenv('PLUGIN_GRANTS')), true) ?: [];
    $grant = ['user_id' => (int) $params[0], 'realm_id' => (int) $params[1]];
    $state[$grant['user_id'] . ':' . $grant['realm_id']] = $grant;
    file_put_contents(getenv('PLUGIN_GRANTS'), json_encode($state));
    return true;
}
function db_fetch_cell_prepared($sql, $params = []) {
    $state = json_decode(file_get_contents(getenv('PLUGIN_GRANTS')), true) ?: [];
    if (getenv('PLUGIN_VERIFY_FAIL') === '1') { return false; }
    return isset($state[(int) $params[0] . ':' . (int) $params[1]]) ? 1 : false;
}
PHP;
    file_put_contents($bootstrap, $bootstrapSource);

    $cliSource = file_get_contents($application . '/cli/plugin_manage.php');
    $originalRequire = "require(__DIR__ . '/../include/cli_check.php');";
    expect($cliSource)->toContain($originalRequire);
    $cliSource = str_replace($originalRequire, "require getenv('PLUGIN_TEST_BOOTSTRAP');", $cliSource);
    $cli = $temporary . '/plugin_manage.php';
    file_put_contents($cli, $cliSource);

    $grantsPath = $temporary . '/grants.json';
    file_put_contents($grantsPath, '{}');
    $baseEnvironment = array_merge($_ENV, [
        'PLUGIN_TEST_BOOTSTRAP' => $bootstrap,
        'PLUGIN_ADMIN_USER' => '7',
        'PLUGIN_ADMIN_EXISTS' => '1',
        'PLUGIN_GRANT_FAIL' => '0',
        'PLUGIN_REALMS' => 'one',
        'PLUGIN_VERIFY_FAIL' => '0',
        'PLUGIN_GRANTS' => $grantsPath,
    ]);

    $run = static function (array $environment) use ($cli, $pluginDirectory, $plugin): array {
        $arguments = [PHP_BINARY, $cli, '--plugin=' . $plugin, '--install', '--allperms'];
        if (($environment['PLUGIN_MULTI'] ?? '') === '1') {
            $arguments[] = '--plugin=second_fixture';
        }
        $environment['PLUGIN_TEST_BASE_PATH'] = dirname(dirname($pluginDirectory));
        $process = proc_open(
            $arguments,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $environment
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Could not start isolated plugin process');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        return ['status' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    };

    try {
        $first = $run($baseEnvironment);
        expect($first['status'])->toBe(0, json_encode($first));
        expect($first['stdout'])->toContain('configured administrator');
        expect($first['stderr'])->toBe('');

        $second = $run($baseEnvironment);
        expect($second['status'])->toBe(0, json_encode($second));
        $grants = json_decode(file_get_contents($grantsPath), true);
        expect(array_values($grants))->toBe([['user_id' => 7, 'realm_id' => 112]]);

        $invalidAdmin = $run(array_merge($baseEnvironment, ['PLUGIN_ADMIN_EXISTS' => '0']));
        expect($invalidAdmin['status'])->toBe(1, json_encode($invalidAdmin));
        expect($invalidAdmin['stdout'])->toContain('administrator was not found');
        expect(count(json_decode(file_get_contents($grantsPath), true)))->toBe(1);

        $failedGrant = $run(array_merge($baseEnvironment, ['PLUGIN_GRANT_FAIL' => '1']));
        expect($failedGrant['status'])->toBe(1, json_encode($failedGrant));
        expect($failedGrant['stdout'])->toContain('Could not grant Plugin');
        foreach (['', '0', 'abc', '7.5', '7e0', '01', '-1', '16777216', '4294967296', '999999999999999999999'] as $value) {
            file_put_contents($grantsPath, '{}');
            $result = $run(array_merge($baseEnvironment, ['PLUGIN_ADMIN_USER' => $value]));
            expect($result['status'])->toBe(1, json_encode($result));
            expect($result['stdout'])->toContain('administrator is invalid')->not->toContain('Enabled Plugin');
            expect($result['stderr'])->toBe('');
            expect(json_decode(file_get_contents($grantsPath), true))->toBe([]);
        }
        foreach (['1', '16777215'] as $value) {
            file_put_contents($grantsPath, '{}');
            $result = $run(array_merge($baseEnvironment, ['PLUGIN_ADMIN_USER' => $value]));
            expect($result['status'])->toBe(0, json_encode($result));
            expect(array_values(json_decode(file_get_contents($grantsPath), true)))->toBe([['user_id' => (int) $value, 'realm_id' => 112]]);
        }
        foreach ([['PLUGIN_REALMS' => 'failure'], ['PLUGIN_VERIFY_FAIL' => '1'], ['PLUGIN_REALMS' => 'two', 'PLUGIN_GRANT_FAIL' => 'first']] as $failure) {
            file_put_contents($grantsPath, '{}');
            $result = $run(array_merge($baseEnvironment, $failure));
            expect($result['status'])->toBe(1, json_encode($result));
            expect($result['stdout'])->toContain('ERROR:')->not->toContain('Enabled Plugin');
            expect($result['stderr'])->toBe('');
            $grants = array_values(json_decode(file_get_contents($grantsPath), true));
            expect($grants)->toBe(isset($failure['PLUGIN_GRANT_FAIL']) ? [['user_id' => 7, 'realm_id' => 113]] : (isset($failure['PLUGIN_VERIFY_FAIL']) ? [['user_id' => 7, 'realm_id' => 112]] : []));
        }
        file_put_contents($grantsPath, '{}');
        $result = $run(array_merge($baseEnvironment, ['PLUGIN_REALMS' => 'empty']));
        expect($result['status'])->toBe(0, json_encode($result));
        expect($result['stdout'])->toContain('Enabled Plugin');
        expect(json_decode(file_get_contents($grantsPath), true))->toBe([]);

        mkdir($temporary.'/plugins/second_fixture', 0700);
        file_put_contents($grantsPath, '{}');
        $result = $run(array_merge($baseEnvironment, ['PLUGIN_MULTI' => '1','PLUGIN_GRANT_FAIL' => 'first']));
        expect($result['status'])->toBe(1, json_encode($result))->and($result['stderr'])->toBe('');
        expect($result['stdout'])->toContain("Enabled Plugin 'second_fixture'")->not->toContain("Enabled Plugin 'allperms_fixture'");
        expect(array_values(json_decode(file_get_contents($grantsPath), true)))->toBe([['user_id' => 7,'realm_id' => 113]]);
    } finally {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($temporary);
    }
});
