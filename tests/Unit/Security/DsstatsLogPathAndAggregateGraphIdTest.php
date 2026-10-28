<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * The dsstats log path is appended to a background shell command, and the
 * aggregate graph permission list interpolated its graph id into SQL after only
 * a numeric comparison. Upstream Cacti #7844 and #7847. Production functions
 * are loaded from their source files and run with a recording environment.
 */

namespace Kadupul\Tests\Security\DsstatsLogPathAndAggregateGraphId;

require_once dirname(__DIR__, 2) . '/Helpers/PhpSource.php';

const CACTI_ESCAPE_CHARACTER = '"';

function read_config_option(string $name): mixed
{
    return $GLOBALS['option_values'][$name] ?? '';
}

function read_user_setting(string $name, mixed $default = '', bool $force = false, int $user = 0): mixed
{
    return '';
}

function exec_background(string $command, string $args = ''): void
{
    $GLOBALS['background'][] = [$command, $args];
}

function auth_valid_user(mixed $user_id): bool
{
    return true;
}

function get_simple_graph_perms(mixed $user_id): bool
{
    return true;
}

function get_policies(mixed $user_id): array
{
    return [];
}

function get_total_row_data(mixed $user, string $sql, array $params, string $type): int
{
    $GLOBALS['sql_statements'][] = $sql;

    return 0;
}

function db_fetch_assoc(string $sql): array
{
    $GLOBALS['sql_statements'][] = $sql;

    return [];
}

$root = dirname(__DIR__, 3);
foreach ([['lib/dsstats.php', 'dsstats_poller_bottom'], ['lib/functions.php', 'cacti_escapeshellarg'], ['lib/auth.php', 'auth_resource_id'], ['lib/auth.php', 'get_allowed_aggregate_graphs']] as [$file, $function]) {
    $source = file_get_contents($root . '/' . $file);
    if (!is_string($source)) {
        throw new \RuntimeException('Unable to read ' . $file . '.');
    }
    eval('namespace ' . __NAMESPACE__ . '; ' . test_php_function_source($source, $function)); // nosemgrep: php.lang.security.eval-use.eval-use
}

function dsstatsCommand(string $os, string $logPath): string
{
    global $config;

    $library = sys_get_temp_dir() . '/dsstats-lib-' . bin2hex(random_bytes(6));
    mkdir($library, 0700);
    file_put_contents($library . '/poller.php', '<?php');
    $config = ['library_path' => $library, 'base_path' => $library, 'cacti_server_os' => $os];
    $GLOBALS['option_values'] = ['dsstats_enable' => 'on', 'path_php_binary' => '/usr/bin/php', 'path_dsstats_log' => $logPath];
    $GLOBALS['background'] = [];

    try {
        dsstats_poller_bottom();
    } finally {
        unlink($library . '/poller.php');
        rmdir($library);
    }

    return $GLOBALS['background'][0][1];
}

test('the dsstats log path is one quoted shell word', function () {
    $args = dsstatsCommand('unix', '/var/log/ds stats.log; touch /tmp/x');

    expect($args)->toContain(">> '/var/log/ds stats.log; touch /tmp/x' 2>&1");
});

test('a dsstats log path with a quote cannot end the quoting', function () {
    $args = dsstatsCommand('unix', "/var/log/a'b\$(id).log");

    expect($args)->toContain(">> '/var/log/a'\\''b\$(id).log' 2>&1");
});

test('the dsstats log redirect is quoted on Windows too', function () {
    $args = dsstatsCommand('win32', 'C:\\logs\\dsstats.log');

    expect($args)->toContain('>> "C:\\logs\\dsstats.log"')->and($args)->not->toContain('2>&1');
});

test('an unset dsstats log path adds no redirect', function () {
    expect(dsstatsCommand('unix', ''))->not->toContain('>>');
});

test('the aggregate graph permission list binds only a numeric graph id', function (mixed $graphId, ?string $expected) {
    $GLOBALS['sql_statements'] = [];
    $total = 99;

    $result = get_allowed_aggregate_graphs('', 'gtg.title_cache', '', $total, 1, $graphId);

    expect($result)->toBe([]);

    if ($expected === null) {
        expect($GLOBALS['sql_statements'])->toBe([])->and($total)->toBe(0);
    } else {
        expect($GLOBALS['sql_statements'])->not->toBe([]);
        expect($GLOBALS['sql_statements'][0])->toContain($expected);
    }
})->with([
    'integer' => [42, 'gl.id = 42'],
    'numeric string' => ['0042', 'gl.id = 42'],
    'no graph' => [0, "WHERE ((h.id > 0 AND h.deleted = '')"],
    'injected text' => ['1 OR 1=1', null],
    'trailing text' => ['7 UNION SELECT 1', null],
]);
