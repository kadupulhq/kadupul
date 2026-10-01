<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace NeverReturnTypeTest;

require_once dirname(__DIR__, 2) . '/Helpers/PhpSource.php';

function source(string $file, string $function): string
{
    return \test_php_function_source(file_get_contents(dirname(__DIR__, 3) . '/' . $file), $function);
}

/** Execute the actual function in isolation so exit cannot terminate Pest. */
function execute(string $code): array
{
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    expect($process)->not->toBeFalse();
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    expect($errors)->toBe('');

    return [$status, $output];
}

test('terminating production functions expose the never contract', function (string $file, string $function) {
    $namespace = __NAMESPACE__ . '\\Contract' . md5($file . $function);
    eval('namespace ' . $namespace . ';' . source($file, $function));
    $reflection = new \ReflectionFunction($namespace . '\\' . $function);
    expect((string) $reflection->getReturnType())->toBe('never');
})->with([
    ['automation_devices.php', 'purge_discovery_results'],
    ['automation_snmp.php', 'automation_snmp_item_dnd'],
    ['automation_templates.php', 'automation_template_dnd'],
    ['cli/md5sum.php', 'fail'],
    ['color.php', 'form_save'],
    ['color_templates_items.php', 'color_templates_item_dnd'],
    ['graphs.php', 'form_save'],
    ['include/csrf.php', 'csrf_error_callback'],
    ['lib/auth.php', 'auth_login_redirect'],
    ['lib/database.php', 'db_warning_handler'],
    ['lib/functions.php', 'raise_message_javascript'],
    ['lib/functions.php', 'cacti_header'],
    ['lib/functions.php', 'cacti_redirect'],
    ['lib/html_reports.php', 'reports_form_save'],
    ['lib/html_validate.php', 'die_html_input_error'],
    ['tree.php', 'tree_down'],
    ['tree.php', 'tree_up'],
    ['tree.php', 'tree_dnd'],
    ['user_admin.php', 'update_policies'],
    ['user_group_admin.php', 'update_policies'],
    ['include/csrf.php', 'cacti_session_cookie_failure'],
    ['user_group_admin.php', 'user_group_refuse'],
]);

test('redirect helpers send the selected location and terminate', function (string $function, string $setup, string $call, array $expected) {
    $code = 'namespace IsolatedRedirect;'
        . 'function validate_redirect_url($url, $default) { return $url === "unsafe" ? $default : $url; }'
        . 'function header(...$args) { echo json_encode($args); }'
        . source('lib/functions.php', $function)
        . $setup . $call . ';echo "REACHED_CALLER";';
    [$status, $output] = execute($code);
    expect($status)->toBe(0)->and(json_decode($output, true))->toBe($expected);
})->with([
    ['cacti_header', '$_SERVER = [];', 'cacti_header()', ['Location: index.php']],
    ['cacti_header', '$_SERVER["HTTP_REFERER"] = "graphs.php";', 'cacti_header()', ['Location: graphs.php']],
    ['cacti_header', '$_SERVER["HTTP_REFERER"] = "unsafe";', 'cacti_header("tree.php")', ['Location: tree.php']],
    ['cacti_redirect', '$_SERVER = [];', 'cacti_redirect()', ['Location: index.php', true, 302]],
    ['cacti_redirect', '$_SERVER["HTTP_REFERER"] = "graphs.php";', 'cacti_redirect()', ['Location: graphs.php', true, 302]],
    ['cacti_redirect', '', 'cacti_redirect("unsafe", "tree.php", 303)', ['Location: tree.php', true, 303]],
    ['cacti_redirect', '', 'cacti_redirect("graphs.php", "index.php", 307)', ['Location: graphs.php', true, 307]],
]);

test('CLI failure preserves output and process status', function (bool $quiet, bool $help, string $expected) {
    $code = source('cli/md5sum.php', 'fail')
        . 'function display_help() { echo "HELP"; }'
        . '$quiet = ' . var_export($quiet, true) . ';$fail_msg = [5 => "Failure: %s"];'
        . 'fail(5, "missing", ' . (int) $help . ');echo "REACHED_CALLER";';
    [$status, $output] = execute($code);
    expect($status)->toBe(5)->and($output)->toBe($expected);
})->with([
    [true, true, ''],
    [false, false, 'Failure: missing'],
    [false, true, 'Failure: missingHELP'],
]);

test('input validation errors terminate in both response formats', function (bool $json) {
    $code = 'function isset_request_var($name) { return ' . var_export($json, true) . '; }'
        . 'function security_log_input_validation_failure($variable) {}'
        . 'function cacti_redact_sensitive($request) { return $request; }'
        . 'function cacti_debug_backtrace(...$args) {}'
        . 'function get_client_addr() { return "127.0.0.1"; }'
        . 'function __($message) { return $message; }'
        . 'function bottom_footer() { echo "FOOTER"; }'
        . '$_REQUEST = [];'
        . source('lib/html_validate.php', 'die_html_input_error')
        . 'die_html_input_error("", "", "Invalid input");echo "REACHED_CALLER";';
    [$status, $output] = execute($code);
    expect($status)->toBe(0);
    if ($json) {
        expect(json_decode($output, true))->toBe([
            'status' => '500',
            'statusText' => 'Validation Error',
            'responseText' => 'Invalid input',
        ]);
    } else {
        expect($output)->toBe("<table style='width:100%;text-align:center;'><tr><td>Invalid input</td></tr></table>FOOTER");
    }
})->with([true, false]);

test('the database warning handler throws the original exception', function () {
    $code = source('lib/database.php', 'db_warning_handler')
        . 'try { db_warning_handler(42, "Database warning", "test.php", 10); echo "REACHED_CALLER"; }'
        . 'catch (Exception $error) { echo json_encode([get_class($error), $error->getMessage(), $error->getCode()]); }';
    [$status, $output] = execute($code);
    expect($status)->toBe(0)
        ->and(json_decode($output, true))->toBe(['Exception', 'Database warning', 42]);
});

test('the SIGINT callback restores terminal echo and exits with status 130', function () {
    $text = file_get_contents(dirname(__DIR__, 3) . '/cli/reset_password.php');
    expect(preg_match('/pcntl_signal\(SIGINT, (function\s*\(\)\s*:\s*never\s*\{.*?\})\);/s', $text, $match))->toBe(1);
    $code = 'function reset_password_echo_on() { echo "RESTORED"; }'
        . '$callback = ' . $match[1] . ';'
        . 'echo (new ReflectionFunction($callback))->getReturnType() . ":";'
        . '$callback();echo "REACHED_CALLER";';
    [$status, $output] = execute($code);
    expect($status)->toBe(130)->and($output)->toBe('never:RESTORED' . PHP_EOL);
});

test('missing session cookies stop the request without a redirect loop', function (bool $writeLog) {
    $code = 'namespace IsolatedCookie;'
        . 'function cacti_log(...$args) { $GLOBALS["events"][] = "log"; }'
        . 'function csrf_log(...$args) { $GLOBALS["events"][] = "csrf_log"; }'
        . 'function http_response_code($status) { $GLOBALS["events"][] = $status; }'
        . 'function header($header) { $GLOBALS["events"][] = $header; }'
        . 'function __($message, ...$args) { return sprintf($message, ...$args); }'
        . '$events = []; $config = ["url_path" => "/cacti/"];'
        . 'register_shutdown_function(function () { echo json_encode($GLOBALS["events"]); });'
        . source('include/csrf.php', 'cacti_session_cookie_failure')
        . 'cacti_session_cookie_failure(' . var_export($writeLog, true) . ');echo "REACHED_CALLER";';
    [$status, $output] = execute($code);
    $events = $writeLog ? ['log', 'csrf_log'] : [];
    $events[] = 403;
    $events[] = 'Content-Type: text/plain; charset=UTF-8';
    expect($status)->toBe(0)->and($output)->toBe(
        'The browser did not return the Cacti session cookie. Ensure cookies are enabled and verify the configured URL path and cookie domain.' . PHP_EOL
        . 'Return to Cacti: /cacti/index.php' . PHP_EOL . json_encode($events)
    );
})->with([true, false]);

test('refusing a missing group logs the denial and terminates after redirecting', function () {
    $code = 'namespace IsolatedGroup;'
        . 'function cacti_log(...$args) { $GLOBALS["events"][] = $args; }'
        . 'function get_client_addr() { return "127.0.0.1"; }'
        . 'function raise_message($message) { $GLOBALS["events"][] = $message; }'
        . 'function header($header) { $GLOBALS["events"][] = $header; }'
        . '$events = [];'
        . 'register_shutdown_function(function () { echo json_encode($GLOBALS["events"]); });'
        . source('user_group_admin.php', 'user_group_refuse')
        . 'user_group_refuse(99);echo "REACHED_CALLER";';
    [$status, $output] = execute($code);
    expect($status)->toBe(0)->and(json_decode($output, true))->toBe([
        ['WARNING: Refused a change to missing User Group ID 99 from IP 127.0.0.1', false, 'AUTH'],
        'permission_denied',
        'Location: user_group_admin.php?header=false',
    ]);
});
