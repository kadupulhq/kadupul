<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * Boost appends its debug output to path_boost_log, so the settings page must
 * refuse a name that does not end in .log, and Boost must ignore such a value
 * if one is already stored (GHSA-m6wx-f538-m6q3). Each case runs the shipped
 * page or function in a child process.
 */

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

function boost_log_child(string $program, array $input = array(), ?string $cwd = null): array
{
    $pipes = array();
    $process = proc_open(
        array(PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=-1', '-r', $program),
        array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes,
        $cwd
    );
    fwrite($pipes[0], json_encode($input));
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    expect($stderr)->toBe('');

    return json_decode($stdout, true) ?? array('raw' => $stdout);
}

function boost_log_functions(string $file, array $names): string
{
    $source = file_get_contents(dirname(__DIR__, 4) . '/' . $file);
    $code = '';

    foreach ($names as $name) {
        $code .= test_php_function_source($source, $name) . "\n";
    }

    return $code;
}

function boost_log_settings_save(string $value): array
{
    $root = dirname(__DIR__, 4);
    $work = sys_get_temp_dir() . '/kadupul-boostlog-' . bin2hex(random_bytes(6));
    mkdir($work . '/include', 0700, true);
    mkdir($work . '/lib', 0700);

    // settings.php includes ./include/auth.php and ./lib/poller.php from the
    // working directory; these stand in for the bootstrap and the database.
    $auth = '<?php' . "\n"
        . '$GLOBALS["input"] = json_decode(stream_get_contents(STDIN), true);' . "\n"
        . '$config = array("poller_id" => 1);' . "\n"
        . '$_SESSION = array();' . "\n"
        . '$GLOBALS["calls"] = array("saved" => array(), "messages" => array());' . "\n"
        . '$settings = array("boost" => array("path_boost_log" => array("method" => "filepath", "file_type" => "ascii")));' . "\n"
        . 'register_shutdown_function(function () { print json_encode(array("calls" => $GLOBALS["calls"], "error_fields" => array_keys($_SESSION["sess_error_fields"] ?? array()))); });' . "\n"
        . 'function set_default_action($default = "") {}' . "\n"
        . 'function get_filter_request_var(...$args) { return "boost"; }' . "\n"
        . 'function get_request_var($name) { return array("action" => "save", "tab" => "boost")[$name] ?? ""; }' . "\n"
        . 'function isset_request_var($name) { return $name === "path_boost_log"; }' . "\n"
        . 'function get_nfilter_request_var($name, $default = "") { return $name === "path_boost_log" ? $GLOBALS["input"]["value"] : $default; }' . "\n"
        . 'function read_config_option($name, $force = false) { return $name === "poller_interval" ? 300 : ""; }' . "\n"
        . 'function db_qstr($value) { return "\'" . addslashes((string) $value) . "\'"; }' . "\n"
        . 'function db_execute_prepared($sql, $params = array(), ...$rest) { $GLOBALS["calls"]["saved"][] = $params; return true; }' . "\n"
        . 'function db_execute(...$args) { return true; }' . "\n"
        . 'function db_fetch_assoc(...$args) { return array(); }' . "\n"
        . 'function array_rekey($array, ...$args) { return array(); }' . "\n"
        . 'function snmpagent_global_settings_update() {}' . "\n"
        . 'function api_plugin_hook_function($name, $data = null) { return $data; }' . "\n"
        . 'function raise_message($id, ...$args) { $GLOBALS["calls"]["messages"][] = $id; }' . "\n"
        . 'function kill_session_var($name) {}' . "\n"
        . 'function cacti_sizeof($array) { return is_array($array) ? count($array) : 0; }' . "\n"
        . boost_log_functions('lib/functions.php', array('is_remote_path_setting', 'is_valid_pathname'));

    file_put_contents($work . '/include/auth.php', $auth);
    file_put_contents($work . '/lib/poller.php', "<?php\n");

    try {
        return boost_log_child('require ' . var_export($root . '/settings.php', true) . ';', array('value' => $value), $work);
    } finally {
        unlink($work . '/include/auth.php');
        unlink($work . '/lib/poller.php');
        rmdir($work . '/include');
        rmdir($work . '/lib');
        rmdir($work);
    }
}

test('the settings page saves a blank or .log Boost debug log path', function (string $value) {
    $result = boost_log_settings_save($value);

    expect($result['calls']['saved'])->toBe(array(array('path_boost_log', $value)))
        ->and($result['error_fields'])->toBe(array())
        ->and($result['calls']['messages'])->toBe(array(1));
})->with(array('blank' => '', 'log file' => '/var/log/kadupul/boost.log'));

test('the settings page refuses a Boost debug log path that does not end in .log', function (string $value, int $error) {
    $result = boost_log_settings_save($value);

    expect($result['calls']['saved'])->toBe(array())
        ->and($result['error_fields'])->toBe(array('path_boost_log'))
        ->and($result['calls']['messages'])->toBe(array(35, $error));
})->with(array(
    'PHP file' => array('/var/www/kadupul/boost.php', 9),
    'no extension' => array('/var/log/kadupul/boost', 9),
    'upper case extension' => array('/var/log/kadupul/boost.LOG', 9),
    'log directory, other file' => array('/var/log/boost.log/boost.txt', 9),
));

test('a .log Boost debug log path still gets the path name check', function () {
    $result = boost_log_settings_save('/var/log/kadupul/boost log.log');

    expect($result['error_fields'])->toBe(array('path_boost_log'))
        ->and($result['calls']['messages'])->toBe(array(35, 36));
});

test('Boost ignores a stored debug log path that does not end in .log', function (string $stored, string $expected, bool $warned) {
    $program = '$GLOBALS["input"] = json_decode(stream_get_contents(STDIN), true); $GLOBALS["logged"] = array();'
        . 'function read_config_option($name, $force = false) { return $name === "path_boost_log" ? $GLOBALS["input"]["stored"] : ""; }'
        . 'function cacti_log($message, ...$args) { $GLOBALS["logged"][] = $message; }'
        . boost_log_functions('lib/boost.php', array('boost_debug_log_path'))
        . 'print json_encode(array("path" => boost_debug_log_path(), "logged" => $GLOBALS["logged"]));';

    $result = boost_log_child($program, array('stored' => $stored));

    expect($result['path'])->toBe($expected)
        ->and($result['logged'] !== array())->toBe($warned);
})->with(array(
    'blank' => array('', '', false),
    'log file' => array('/var/log/kadupul/boost.log', '/var/log/kadupul/boost.log', false),
    'PHP file' => array('/var/www/kadupul/boost.php', '', true),
));

test('Boost opens its debug log only through the checked path', function () {
    $root = dirname(__DIR__, 4);

    foreach (array('poller_boost.php', 'lib/boost.php') as $file) {
        $source = file_get_contents($root . '/' . $file);

        // Remaining direct reads only decide whether to print debug text.
        expect(preg_match_all("/\\\$\\w+\\s*=\\s*read_config_option\\('path_boost_log'\\)/", $source))->toBe(0, $file);
    }
});
