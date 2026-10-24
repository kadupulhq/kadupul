<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Two ways into the real lib/database.php on a MariaDB or MySQL server,
// printing the connection state each leaves as JSON:
//
//   bootstrap  runs a byte-identical copy of include/global.php as a command
//              line script against a generated config.php, in a scratch tree
//              that links every other file back to this checkout
//   helpers    calls db_connect_real() and the helpers directly, the way the
//              other native fixtures do, to reach the retry, reconnect and
//              close paths
//
// argv[2] is a JSON object naming the two schemas the test created, with
// cacti.sql loaded, and the scenario's options. Passwords are compared here
// and never printed.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$state_root = dirname(__DIR__, 2);
$state_mode = $argv[1] ?? '';
$state_options = json_decode($argv[2] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
parse_str(str_replace(';', '&', substr((string) getenv('KADUPUL_TEST_MYSQL_DSN'), strlen('mysql:'))), $state_dsn);
$state_prefix = getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') ? 'KADUPUL_TEST_MYSQL_ADMIN_' : 'KADUPUL_TEST_MYSQL_';
$state_user = getenv($state_prefix . 'USER') ?: 'root';
$state_password = getenv($state_prefix . 'PASSWORD') ?: '';
$state_host = (string) $state_dsn['host'];
$state_port = (string) ($state_dsn['port'] ?? '3306');

$state_tree = sys_get_temp_dir() . '/kadupul-connection-state-' . bin2hex(random_bytes(8));
foreach (['/include', '/log'] as $directory) {
    if (!mkdir($state_tree . $directory, 0700, true)) {
        throw new RuntimeException('Cannot create the scratch tree.');
    }
}
// A config.php for the reconnect path to read; touch() leaves a mark when it does.
$state_marker = $state_tree . '/config-was-read';

function state_config(array $values, string $marker): string
{
    $source = "<?php\n";
    foreach ($values as $name => $value) {
        $source .= '$' . $name . ' = ' . var_export($value, true) . ";\n";
    }

    return $source . 'touch(' . var_export($marker, true) . ");\n";
}

$state_local = [
    'database_type' => 'mysql', 'database_default' => $state_options['local'], 'database_hostname' => $state_host,
    'database_username' => $state_user, 'database_password' => $state_password, 'database_port' => $state_port,
    'database_retries' => 2, 'database_ssl' => false, 'database_ssl_key' => '', 'database_ssl_cert' => '', 'database_ssl_ca' => '',
];

/** @return array<string, mixed> each connection global's type, and its value where that is not a secret or a handle */
function state_globals(array $names, array $handles): array
{
    $seen = [];
    foreach ($names as $name) {
        if (!array_key_exists($name, $GLOBALS)) {
            $seen[$name] = 'unset';
            continue;
        }
        $value = $GLOBALS[$name];
        if ($value instanceof PDO) {
            $seen[$name] = 'PDO ' . (array_search($value, $handles, true) ?: 'other');
        } elseif (str_ends_with($name, 'password')) {
            $seen[$name] = get_debug_type($value);
        } else {
            $seen[$name] = [get_debug_type($value), $value];
        }
    }

    return $seen;
}

/** @return array<string, mixed> */
function state_sessions(array $handles): array
{
    $sessions = [];
    foreach ($GLOBALS['database_sessions'] ?? [] as $key => $session) {
        $sessions[$key] = $session instanceof PDO ? (array_search($session, $handles, true) ?: 'other') : get_debug_type($session);
    }
    $details = [];
    foreach ($GLOBALS['database_details'] ?? [] as $hash => $detail) {
        $row = [];
        foreach ($detail as $field => $value) {
            if ($field === 'database_conn') {
                $row[$field] = spl_object_hash($value) === $hash ? 'own handle' : 'other handle';
            } elseif ($field === 'database_password') {
                $row[$field] = [get_debug_type($value), $value === $GLOBALS['state_password']];
            } else {
                $row[$field] = [get_debug_type($value), $value];
            }
        }
        $details[] = $row;
    }

    return ['sessions' => $sessions, 'details' => $details];
}

/** Replaces this run's schema names, server and user so output compares across runs. */
function state_normalize(mixed $value, array $names): mixed
{
    if (is_array($value)) {
        $normal = [];
        foreach ($value as $key => $item) {
            $normal[is_string($key) ? strtr($key, $names) : $key] = state_normalize($item, $names);
        }

        return $normal;
    }

    return is_string($value) && isset($names[$value]) ? $names[$value] : $value;
}

function state_database(mixed $connection = false): mixed
{
    return db_fetch_cell('SELECT DATABASE()', '', false, $connection);
}

$state_names = [
    'database_type', 'database_default', 'database_hostname', 'database_username', 'database_password', 'database_port',
    'database_retries', 'database_ssl', 'database_ssl_key', 'database_ssl_cert', 'database_ssl_ca', 'database_persist',
    'rdatabase_type', 'rdatabase_default', 'rdatabase_hostname', 'rdatabase_username', 'rdatabase_password', 'rdatabase_port',
    'rdatabase_retries', 'rdatabase_ssl', 'rdatabase_ssl_key', 'rdatabase_ssl_cert', 'rdatabase_ssl_ca',
    'local_db_cnn_id', 'remote_db_cnn_id', 'conn_mode',
];

if ($state_mode === 'bootstrap') {
    foreach (scandir($state_root) as $entry) {
        if (!in_array($entry, ['.', '..', 'include', 'log', '.git'], true) && !symlink($state_root . '/' . $entry, $state_tree . '/' . $entry)) {
            throw new RuntimeException('Cannot link ' . $entry);
        }
    }
    foreach (scandir($state_root . '/include') as $entry) {
        if (!in_array($entry, ['.', '..', 'global.php', 'config.php'], true) && !symlink($state_root . '/include/' . $entry, $state_tree . '/include/' . $entry)) {
            throw new RuntimeException('Cannot link include/' . $entry);
        }
    }
    // A copy, because global.php derives every path from its own location.
    if (!copy($state_root . '/include/global.php', $state_tree . '/include/global.php')) {
        throw new RuntimeException('Cannot copy include/global.php');
    }
    $state_values = $state_local + ['poller_id' => $state_options['poller_id']];
    if (!empty($state_options['remote'])) {
        foreach ($state_local as $name => $value) {
            $state_values['r' . $name] = $value;
        }
        $state_values['rdatabase_default'] = $state_options['main'];
        $state_values['rdatabase_port'] = $state_options['remote_port'] ?? $state_port;
        // global.php supplies these when config.php leaves them out.
        unset($state_values['rdatabase_retries'], $state_values['rdatabase_ssl'], $state_values['rdatabase_ssl_key'], $state_values['rdatabase_ssl_cert'], $state_values['rdatabase_ssl_ca']);
    }
    if (isset($state_options['conn_mode'])) {
        $state_values['conn_mode'] = $state_options['conn_mode'];
    }
    file_put_contents($state_tree . '/include/config.php', state_config($state_values, $state_marker));

    $no_http_headers = true;
    require $state_tree . '/include/global.php';
    restore_error_handler();

    $state_handles = ['local' => $local_db_cnn_id ?? null, 'remote' => $remote_db_cnn_id ?? null];
    $state_handles = array_filter($state_handles, static fn(mixed $handle): bool => $handle instanceof PDO);
    // On the main poller the only session is the default one.
    if ($state_handles === [] && isset($database_sessions["$database_hostname:$database_port:$database_default"])) {
        $state_handles['default'] = $database_sessions["$database_hostname:$database_port:$database_default"];
    }
    $state_out = [
        'globals' => state_globals($state_names, $state_handles),
        'connection' => $config['connection'],
        'cacti_db_version' => get_debug_type($config['cacti_db_version'] ?? null),
        'database_error_codes' => array_column($config['DATABASE_ERROR'] ?? [], 'Code'),
        'default_database' => state_database() === $state_options['local'] ? 'local' : state_database(),
    ] + state_sessions($state_handles);
    $state_out['force_remote'] = var_export(db_force_remote_cnn(), true);
    $state_after = state_database();
    $state_out['default_after_force'] = $state_after === $state_options['main'] ? 'main' : ($state_after === $state_options['local'] ? 'local' : var_export($state_after, true));
    print json_encode(state_normalize($state_out, [$state_options['local'] => '{local}', $state_options['main'] => '{main}', $state_host => '{host}', $state_port => '{port}', $state_user => '{user}', "$state_host:$state_port:" => '{host}:{port}:']), JSON_THROW_ON_ERROR);
    exit;
}

if ($state_mode !== 'helpers') {
    fwrite(STDERR, 'unknown mode');
    exit(1);
}

require $state_root . '/include/global_constants.php';
require $state_root . '/lib/database.php';
require $state_root . '/tests/Helpers/PhpSource.php';
// The repository's own helpers, extracted as the other native fixtures do.
foreach (['cacti_sizeof', 'cacti_count', 'clean_up_lines', 'array_rekey'] as $function) {
    eval(test_php_function_source(file_get_contents($state_root . '/lib/functions.php'), $function));
}

$logged = [];

// Same signatures as lib/functions.php; they record instead of writing the
// log. Declared conditionally so the bootstrap mode can load the real ones.
if (!function_exists('cacti_log')) {
    function cacti_log($string, $output = false, $environ = 'CMDPHP', $level = '')
    {
        $GLOBALS['logged'][] = [$string, $environ, $level];

        return true;
    }

    function cacti_debug_backtrace($entry = '', $html = false, $record = true, $limit = 0, $skip = 0)
    {
        $GLOBALS['logged'][] = ['backtrace', $entry];
    }
}

file_put_contents($state_tree . '/include/config.php', state_config($state_local, $state_marker));
$config = ['poller_id' => 1, 'base_path' => $state_tree];
$database_hostname = $state_host;
$database_port = $state_port;
$database_default = $state_options['local'];
$state_out = [];

// Local connect, and the cached handle on a second call.
$local_db_cnn_id = db_connect_real($state_host, $state_user, $state_password, $state_options['local'], 'mysql', $state_port, 2);
$state_handles = ['local' => $local_db_cnn_id];
$state_out['connect'] = [
    'type' => get_debug_type($local_db_cnn_id),
    'cached' => db_connect_real($state_host, $state_user, $state_password, $state_options['local'], 'mysql', $state_port, 2) === $local_db_cnn_id,
    'total_queries_after_connect' => $database_total_queries,
    'errmode' => $local_db_cnn_id->getAttribute(PDO::ATTR_ERRMODE),
    'persistent' => $local_db_cnn_id->getAttribute(PDO::ATTR_PERSISTENT),
    'sql_mode' => explode(',', (string) db_fetch_cell('SELECT @@SESSION.sql_mode')),
    'charset' => db_fetch_cell('SELECT @@SESSION.character_set_client'),
    'default_database' => state_database() === $state_options['local'] ? 'local' : state_database(),
    'config_read' => file_exists($state_marker),
] + state_sessions($state_handles);

// The collector's second connection, then the web override global.php makes
// on a collector: the default key names the main database from then on.
$database_persist = true;
$remote_db_cnn_id = db_connect_real($state_host, $state_user, $state_password, $state_options['main'], 'mysql', $state_port, 2);
unset($database_persist);
$state_handles['remote'] = $remote_db_cnn_id;
$state_out['remote'] = [
    'type' => get_debug_type($remote_db_cnn_id),
    'distinct' => $remote_db_cnn_id !== $local_db_cnn_id,
    'persistent' => $remote_db_cnn_id->getAttribute(PDO::ATTR_PERSISTENT),
    'default_before_override' => state_database() === $state_options['local'] ? 'local' : state_database(),
    'explicit' => state_database($remote_db_cnn_id) === $state_options['main'],
] + state_sessions($state_handles);
$database_default = $state_options['main'];
$state_out['remote']['default_after_override'] = state_database() === $state_options['main'] ? 'main' : state_database();
$database_default = $state_options['local'];
$config['poller_id'] = 2;
$state_out['remote']['force'] = var_export(db_force_remote_cnn(), true);
$state_out['remote']['default_after_force'] = state_database() === $state_options['main'] ? 'main' : state_database();
$state_out['remote']['local'] = var_export(db_switch_main_to_local(), true);
$state_out['remote']['default_after_local'] = state_database() === $state_options['local'] ? 'local' : state_database();
$config['poller_id'] = 1;

// A refused port: every attempt is recorded, nothing is registered.
$state_before = array_keys($database_sessions);
$state_out['refused'] = [
    'result' => var_export(db_connect_real($state_host, $state_user, $state_password, $state_options['local'], 'mysql', '1', 1), true),
    'codes' => array_column($config['DATABASE_ERROR'] ?? [], 'Code'),
    'leaks' => $state_password !== '' && str_contains(json_encode($config['DATABASE_ERROR'] ?? []), $state_password),
    'sessions_unchanged' => array_keys($database_sessions) === $state_before,
];
unset($config['DATABASE_ERROR']);

// A live handle passes the reconnect check without a new session.
$state_out['check_alive'] = [
    'result' => db_check_reconnect($remote_db_cnn_id),
    'sessions_unchanged' => array_keys($database_sessions) === $state_before,
    'config_read' => file_exists($state_marker),
];
@unlink($state_marker);

// The server drops the default session. The failing call retries on the
// handle it was given, so it fails, but the default session is replaced
// from config.php and the next call works.
$state_observer = new PDO('mysql:host=' . $state_host . ';port=' . $state_port, $state_user, $state_password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$state_killed = (int) db_fetch_cell('SELECT CONNECTION_ID()');
$state_observer->exec('KILL ' . $state_killed);
usleep(200000);
$logged = [];
$state_dropped = db_fetch_cell('SELECT 41');
$state_replacement = $database_sessions["$database_hostname:$database_port:$database_default"];
$state_out['reconnect'] = [
    'dropped' => var_export($state_dropped, true),
    'logged' => $logged,
    'error' => db_error(),
    'config_read' => file_exists($state_marker),
    'replaced' => $state_replacement instanceof PDO && $state_replacement !== $local_db_cnn_id,
    'next' => db_fetch_cell('SELECT 42'),
    'new_session' => (int) db_fetch_cell('SELECT CONNECTION_ID()') !== $state_killed,
    'remote_kept' => state_database($remote_db_cnn_id) === $state_options['main'],
];
$state_handles['replacement'] = $state_replacement;
$state_out['reconnect'] += state_sessions($state_handles);

// db_close() on the default leaves the key holding null; a passed handle is
// released through the reference without touching the sessions.
$state_explicit = $remote_db_cnn_id;
$state_out['close'] = [
    'default' => db_close(),
    'session' => get_debug_type($database_sessions["$database_hostname:$database_port:$database_default"]),
    'after' => var_export(db_fetch_cell('SELECT 1'), true),
    'error' => db_error(),
    'again' => db_close(),
    'explicit' => db_close($state_explicit),
    'explicit_after' => get_debug_type($state_explicit),
    'remote_still_open' => state_database($remote_db_cnn_id) === $state_options['main'],
];

print json_encode(state_normalize($state_out, [$state_options['local'] => '{local}', $state_options['main'] => '{main}', $state_host => '{host}', $state_port => '{port}', $state_user => '{user}', "$state_host:$state_port:" => '{host}:{port}:']), JSON_THROW_ON_ERROR);
