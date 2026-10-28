<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * GHSA-rjvj-r52f-8v5q (upstream Cacti #8055 and #8056): on Windows the data
 * input, data query script, ping and SNMP trap commands run through cmd.exe,
 * which ignores the backslash escape and toggles quoting on every quotation
 * mark. Device and receiver values must lose the characters cmd.exe acts on,
 * and a hostname also loses '%'. Unix commands are unchanged. The production
 * functions run in a child process with the server OS set as the poller sees it.
 */

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

const WIN32_CMD_OPERATORS = array('"', '&', '|', '^', '<', '>', '(', ')');

function win32_cmd_run(string $os, string $program, array $arguments = array(), bool $functions = true): mixed
{
    $root = dirname(__DIR__, 4);
    $library = sys_get_temp_dir() . '/win32-cmd-' . bin2hex(random_bytes(6));
    mkdir($library, 0700);
    // Stand-ins for includes the extracted functions load themselves.
    file_put_contents($library . '/variables.php', '<?php');
    file_put_contents($library . '/poller.php', '<?php');
    $prelude = '$config = ["is_web" => false, "cacti_server_os" => $argv[2], "base_path" => "C:/kadupul", "library_path" => $argv[3],'
        . ' "config_options_array" => ["path_php_binary" => "php.exe", "path_snmpget" => "snmpget.exe"]];'
        . 'require $argv[1] . "/include/global_constants.php";'
        . ($functions ? 'require $argv[1] . "/lib/functions.php";' : '')
        . '$arguments = json_decode($argv[4], true, 512, JSON_THROW_ON_ERROR);';
    try {
        $process = proc_open(array(PHP_BINARY, '-d', 'display_errors=stderr', '-r', $prelude . $program, $root, $os, $library, json_encode($arguments, JSON_THROW_ON_ERROR)), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0, $err . $out)->and($err)->toBe('');
    } finally {
        array_map('unlink', glob($library . '/*'));
        rmdir($library);
    }

    return json_decode($out, true, 512, JSON_THROW_ON_ERROR);
}

function win32_cmd_function(string $file, string $name): string
{
    return test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/' . $file), $name) . "\n";
}

function win32_cmd_expect_clean(string $command): void
{
    // The quotation marks are the escaper's own; every other operator must be gone.
    $bare = str_replace('"', '', $command);
    foreach (array_diff(WIN32_CMD_OPERATORS, array('"')) as $operator) {
        expect(strpos($bare, $operator))->toBeFalse($operator . ' in ' . $command);
    }
}

test('data input commands drop cmd.exe operators from device values on Windows', function (string $caller) {
    $result = win32_cmd_run(
        'win32',
        'require $argv[1] . "/tests/Fixtures/data-input-substitution-native.php";'
        . 'echo json_encode(data_input_command($arguments["caller"], "<path_cacti>/scripts/check.php <hostname> <community> <ip>",'
        . ' ["hostname" => "%COMSPEC%\\" & calc", "community" => "pa%ss\\" | del x", "ip" => "%TEMP%^>out"], ["hostname" => "%COMSPEC%\\" & calc", "id" => 9], ["hostname" => "hostname", "ip" => "hostname"]));',
        array('caller' => $caller)
    );

    // Hostname fields lose '%' as well; a community may contain one.
    expect($result)->toBe('C:/kadupul/scripts/check.php "COMSPEC  calc" "pa%ss  del x" "TEMPout"');
})->with(array('poller', 'test'));

test('data input commands keep their Unix quoting', function () {
    $result = win32_cmd_run('unix', 'require $argv[1] . "/tests/Fixtures/data-input-substitution-native.php";'
        . 'echo json_encode(data_input_command("poller", "<path_cacti>/scripts/check.php <hostname>", ["hostname" => "%h (x) & y"], [], ["hostname" => "hostname"]));');

    expect($result)->toBe("C:/kadupul/scripts/check.php '%h (x) & y'");
});

test('data query script arguments drop cmd.exe operators and keep credential percent signs', function () {
    $program = win32_cmd_function('lib/data_query.php', 'get_script_query_path')
        . 'function substitute_script_query_path($path) { return $path; }'
        . 'function substitute_host_data($s, $l, $r, $id) { return strtr($s, ["|host_hostname|" => "%USERNAME%\" & calc", "|host_snmp_community|" => "c%m\" > x", "|query_index|" => "1) & (echo"]); }'
        . 'echo json_encode(get_script_query_path("|host_hostname| |host_snmp_community| index |query_index|", "C:/kadupul/scripts/q.php", 9));';
    $result = win32_cmd_run('win32', $program);

    expect($result)->toBe('C:/kadupul/scripts/q.php "USERNAME  calc" "c%m  x" "index" "1  echo"');
});

test('the Windows ping hostname drops cmd.exe operators and percent signs', function () {
    $source = file_get_contents(dirname(__DIR__, 4) . '/lib/ping.php');
    preg_match("/shell_exec\\('chcp 437 && ping -w ' . [^;]+;/", $source, $call);
    expect($call)->toHaveCount(1);
    $program = '$host = ["hostname" => "%COMPUTERNAME%\" & calc (x)"];'
        . 'echo json_encode(cacti_escapeshellarg($host["hostname"], true, true, true));';
    // The call site passes the hostname-only flags the helper is exercised with here.
    expect($call[0])->toContain("cacti_escapeshellarg(\$this->host['hostname'], true, true, true)");

    expect(win32_cmd_run('win32', $program))->toBe('"COMPUTERNAME  calc x"');
});

test('SNMP trap arguments drop cmd.exe operators and the log still redacts credentials', function () {
    $program = win32_cmd_function('lib/snmpagent.php', 'snmpagent_notification')
        . win32_cmd_function('lib/functions.php', 'cacti_escapeshellarg')
        . win32_cmd_function('lib/functions.php', 'cacti_is_sensitive_key')
        . win32_cmd_function('lib/functions.php', 'cacti_sizeof')
        . win32_cmd_function('lib/functions.php', 'cacti_escapeshellcmd')
        // global_constants.php defines the severities.
        . '$snmpagent_event_severity = [1 => "low", 2 => "medium", 3 => "high", 4 => "critical"];'
        . 'function read_config_option($name) { return $name === "path_snmptrap" ? "C:/net-snmp/bin/snmptrap.exe" : ""; }'
        . 'function db_fetch_cell_prepared($sql, $params = []) { return ".1.3.6.1.4.1.23925.1.6"; }'
        . 'function db_fetch_assoc_prepared($sql, $params = []) {'
        . '  if (str_contains($sql, "snmpagent_cache_notifications")) { return [["attribute" => "cactiEventDescription", "oid" => ".1.3.6.1.4.1.23925.1.5", "type" => "DisplayString", "tcType" => null]]; }'
        . '  return [["id" => 1, "hostname" => "%LOGONSERVER%\" & calc", "snmp_port" => 162, "snmp_version" => $GLOBALS["arguments"]["version"], "snmp_community" => "pub(\"x", "snmp_message_type" => 1,'
        . '    "snmp_engine_id" => "80001f88^", "snmp_username" => "user", "snmp_password" => "pa(ss)wd%1", "snmp_auth_protocol" => "SHA", "snmp_priv_passphrase" => "", "snmp_priv_protocol" => "[None]"]];'
        . '}'
        . 'function exec_background($file, $args) { $GLOBALS["sent"] = $args; }'
        . 'function sql_save($save, $table) { return 1; }'
        . 'function cacti_log($message, ...$rest) { if (str_starts_with($message, "NOTE:")) { $GLOBALS["logged"] = $message; } }'
        . 'snmpagent_notification("cactiNotifyDeviceDown", "CACTI-MIB", ["cactiEventDescription" => "down\" & del (all)"]);'
        . 'echo json_encode(["sent" => $GLOBALS["sent"], "logged" => $GLOBALS["logged"]]);';

    foreach (array(2, 3) as $version) {
        $result = win32_cmd_run('win32', $program, array('version' => $version), false);
        win32_cmd_expect_clean($result['sent']);
        expect($result['sent'])->toContain('"LOGONSERVER  calc:162"')
            ->and($result['sent'])->toContain('"down  del all"');
        if ($version === 2) {
            expect($result['sent'])->toContain('-c "pubx"');
        } else {
            expect($result['sent'])->toContain('-A "passwd%1"')
                ->and($result['logged'])->not->toContain('passwd%1')
                ->and($result['logged'])->toContain('[REDACTED]');
        }
    }
});

test('the deprecated SNMPv3 auth string drops cmd.exe operators on Windows', function () {
    $program = win32_cmd_function('lib/snmp.php', 'snmp_escape_string')
        . 'echo json_encode(snmp_escape_string("pa\" & calc (x)"));';

    expect(win32_cmd_run('win32', $program))->toBe('"pa  calc x"');
});

test('Unix escaping is unchanged by the cmd.exe flags', function () {
    $program = 'echo json_encode([cacti_escapeshellarg("a\" & %b (c)", true, true, true), escapeshellarg("a\" & %b (c)")]);';
    [$escaped, $native] = win32_cmd_run('unix', $program);

    expect($escaped)->toBe($native);
});

test('RRDtool commands cannot carry a second line', function (string $command, string $expected) {
    $program = win32_cmd_function('lib/rrd.php', 'escape_command')
        . 'echo json_encode(escape_command($arguments["command"]));';

    expect(win32_cmd_run('unix', $program, array('command' => $command)))->toBe($expected);
})->with(array(
    'line feed' => array("update a.rrd N:1\ngraph x.png", 'update a.rrd N:1 graph x.png'),
    'carriage return' => array("update a.rrd N:1\r\ninfo b.rrd", 'update a.rrd N:1  info b.rrd'),
    'single line' => array('update a.rrd N:1', 'update a.rrd N:1'),
));

test('SNMP protocol fields accept only the listed protocols', function (string $file, string $field, string $value, bool $valid) {
    $tokens = array_values(array_filter(token_get_all(file_get_contents(dirname(__DIR__, 4) . '/' . $file)), static fn($token) => !is_array($token) || !in_array($token[0], array(T_WHITESPACE, T_COMMENT), true)));
    $regex = null;
    foreach ($tokens as $index => $token) {
        if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== 'form_input_validate') {
            continue;
        }
        // form_input_validate(value, 'field', "pattern", ...): the second and third arguments.
        $depth = 0;
        $literals = array();
        for ($next = $index + 1; isset($tokens[$next]); $next++) {
            $part = $tokens[$next];
            $depth += $part === '(' ? 1 : ($part === ')' ? -1 : 0);
            if ($depth === 1 && is_array($part) && $part[0] === T_CONSTANT_ENCAPSED_STRING) {
                $literals[] = $part[1];
            }
            if ($depth === 0) {
                break;
            }
        }
        if (($literals[0] ?? '') === "'" . $field . "'" && isset($literals[1])) {
            // The patterns are double-quoted literals without escape sequences PHP expands.
            $regex = substr($literals[1], 1, -1);
            break;
        }
    }
    expect($regex)->toBeString();
    $program = win32_cmd_function('lib/functions.php', 'form_input_validate');
    $program = str_replace('require $argv[1] . "/lib/functions.php";', '', $program);
    $program = 'function read_config_option($n) { return ""; } function raise_message($id) {} function cacti_log(...$a) {}'
        . test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/lib/functions.php'), 'form_input_validate')
        . '$_SESSION = []; form_input_validate($arguments["value"], "field", $arguments["regex"], true, 3);'
        . 'echo json_encode(empty($_SESSION["sess_error_fields"]));';
    $process = proc_open(array(PHP_BINARY, '-d', 'display_errors=stderr', '-r', '$arguments = json_decode($argv[1], true);' . $program, json_encode(array('value' => $value, 'regex' => $regex))), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0)->and($err)->toBe('');

    expect(json_decode($out))->toBe($valid);
})->with(function () {
    foreach (array('lib/api_device.php', 'managers.php') as $file) {
        foreach (array('[None]' => true, 'MD5' => true, 'SHA' => true, 'SHA384' => true, 'SHA512' => true, 'SHA392' => false, 'SHA256 -X x' => false, 'xMD5' => false) as $value => $valid) {
            yield $file . ' auth ' . $value => array($file, 'snmp_auth_protocol', $value, $valid);
        }
        foreach (array('DES' => true, 'AES256C' => true, 'DES; x' => false, 'yAES' => false) as $value => $valid) {
            yield $file . ' priv ' . $value => array($file, 'snmp_priv_protocol', $value, $valid);
        }
    }
});
