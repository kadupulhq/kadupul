<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * Data input commands built by the production get_full_script_path() (poller
 * cache) and get_full_test_script_path() (test_data_source) are parsed by a
 * real /bin/sh. The cases follow Cacti PR #8238 (GHSA-5v3j-wcrr-jxjg).
 */

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
require_once dirname(__DIR__, 3) . '/Helpers/ChildProcessCoverage.php';

function data_input_substitution_run(string $kind, string $program, array $arguments = array()): mixed
{
    $root = dirname(__DIR__, 4);
    $prelude = '$config = ["is_web" => false, "cacti_server_os" => "unix", "base_path" => $argv[2],'
        . ' "config_options_array" => ["path_php_binary" => "/usr/bin/php", "path_snmpget" => "/usr/bin/snmpget"]];'
        . 'require $argv[1] . "/include/global_constants.php";'
        . 'require $argv[1] . "/lib/functions.php";'
        . 'require $argv[1] . "/tests/Fixtures/data-input-substitution-native.php";'
        . 'require $argv[1] . "/tests/Fixtures/script-substitution-matrix.php";'
        . '$arguments = json_decode($argv[3], true, 512, JSON_THROW_ON_ERROR);';
    $program = $prelude . $program . '$GLOBALS["nativeChildCoverageMarkers"][] = "data-input-substitution-complete";';
    $registration = child_coverage_registration(
        __FILE__,
        'data-input-substitution-' . $kind,
        array($program, $arguments),
        array('data-input-substitution-complete'),
        array('lib/functions.php', 'src/DataInput/Domain/InputStringSubstitution.php'),
        array(
            'include/global_constants.php', 'lib/path_helpers.php', 'lib/graph_fonts.php',
            'tests/Fixtures/data-input-substitution-native.php', 'tests/Fixtures/script-substitution-matrix.php',
        )
    );
    $base = $arguments['base'] ?? '/opt/kadupul';
    $command = child_coverage_command(
        array(PHP_BINARY, '-r', $program, $root, $base, json_encode($arguments, JSON_THROW_ON_ERROR)),
        $directory,
        $registration
    );
    $result = test_php_run($command);
    expect($result['err'])->toBe('');
    expect($result['status'])->toBe(0);
    child_coverage_collect($directory);

    return json_decode($result['out'], true, 512, JSON_THROW_ON_ERROR);
}

test('bundled data input methods keep their command lines', function () {
    $sql = file_get_contents(dirname(__DIR__, 4) . '/cacti.sql');
    preg_match_all("/^INSERT INTO data_input VALUES \\(\\d+,'[0-9a-f]{32}','[^']*','([^']+)',1\\);$/m", $sql, $shipped);

    $methods = array(
        '<path_cacti>/scripts/diskfree.sh <partition>' => array(
            array('partition' => '/dev/sda1'),
            "/opt/kadupul/scripts/diskfree.sh '/dev/sda1'",
            array('/opt/kadupul/scripts/diskfree.sh', '/dev/sda1'),
        ),
        '<path_php_binary> -q <path_cacti>/scripts/loadavg_multi.php' => array(
            array(),
            '/usr/bin/php -q /opt/kadupul/scripts/loadavg_multi.php',
            array('/usr/bin/php', '-q', '/opt/kadupul/scripts/loadavg_multi.php'),
        ),
        '<path_php_binary> -q <path_cacti>/scripts/unix_users.php <username>' => array(
            array('username' => 'root'),
            "/usr/bin/php -q /opt/kadupul/scripts/unix_users.php 'root'",
            array('/usr/bin/php', '-q', '/opt/kadupul/scripts/unix_users.php', 'root'),
        ),
        '<path_php_binary> -q <path_cacti>/scripts/linux_memory.php <grepstr>' => array(
            array('grepstr' => "Mem Free's"),
            "/usr/bin/php -q /opt/kadupul/scripts/linux_memory.php 'Mem Free'\\''s'",
            array('/usr/bin/php', '-q', '/opt/kadupul/scripts/linux_memory.php', "Mem Free's"),
        ),
        '<path_php_binary> -q <path_cacti>/scripts/unix_processes.php' => array(
            array(),
            '/usr/bin/php -q /opt/kadupul/scripts/unix_processes.php',
            array('/usr/bin/php', '-q', '/opt/kadupul/scripts/unix_processes.php'),
        ),
        'perl <path_cacti>/scripts/unix_tcp_connections.pl <grepstr>' => array(
            array('grepstr' => ''),
            "perl /opt/kadupul/scripts/unix_tcp_connections.pl ''",
            array('perl', '/opt/kadupul/scripts/unix_tcp_connections.pl', ''),
        ),
        'perl <path_cacti>/scripts/webhits.pl <log_path>' => array(
            array('log_path' => '/var/log/httpd/access_log'),
            "perl /opt/kadupul/scripts/webhits.pl '/var/log/httpd/access_log'",
            array('perl', '/opt/kadupul/scripts/webhits.pl', '/var/log/httpd/access_log'),
        ),
        '<path_php_binary> -q <path_cacti>/scripts/ping.php <ip>' => array(
            array('ip' => '192.0.2.10'),
            "/usr/bin/php -q /opt/kadupul/scripts/ping.php '192.0.2.10'",
            array('/usr/bin/php', '-q', '/opt/kadupul/scripts/ping.php', '192.0.2.10'),
        ),
        // Script server shape from the bundled device packages.
        '<path_cacti>/scripts/ss_fping.php ss_fping <hostname> <attempts> <protocol> <port>' => array(
            array('hostname' => 'host.example', 'attempts' => '3', 'protocol' => 'ICMP', 'port' => '23'),
            "/opt/kadupul/scripts/ss_fping.php ss_fping 'host.example' '3' 'ICMP' '23'",
            array('/opt/kadupul/scripts/ss_fping.php', 'ss_fping', 'host.example', '3', 'ICMP', '23'),
        ),
    );

    expect($shipped[1])->toBe(array_slice(array_keys($methods), 0, 8));

    $result = data_input_substitution_run('bundled', <<<'PHP'
$out = array();
foreach ($arguments['methods'] as $template => $method) {
    $poller = data_input_command('poller', $template, $method[0]);
    $test = data_input_command('test', $template, $method[0], array('id' => 9));
    $out[] = array($template, $poller, $test, data_input_shell_words($poller));
}
echo json_encode($out);
PHP, array('methods' => $methods));

    foreach ($result as [$template, $poller, $test, $shell]) {
        [$fields, $expected, $words] = $methods[$template];
        expect($poller)->toBe($expected, $template);
        // test_data_source passes an empty value as an empty string, not ''.
        expect($test)->toBe($fields === array('grepstr' => '') ? 'perl /opt/kadupul/scripts/unix_tcp_connections.pl ' : $expected, $template);
        expect($shell)->toBe(array('status' => 0, 'words' => $words, 'err' => ''), $template);
    }
});

test('host fields reach test_data_source escaped and unchanged', function () {
    $result = data_input_substitution_run('host', <<<'PHP'
echo json_encode(data_input_command('test', '<path_cacti>/scripts/ss_hstats.php ss_hstats <host_id> <hostname> uptime', array('host_id' => '', 'hostname' => ''), array('id' => 9, 'hostname' => "it's here")));
PHP);

    expect($result)->toBe("/opt/kadupul/scripts/ss_hstats.php ss_hstats '9' 'it'\\''s here' uptime");
});

test('quoted placeholders keep field values literal when the shell runs the command', function (string $caller, string $template, array $words) {
    $result = data_input_substitution_run('quoted', <<<'PHP'
$directory = sys_get_temp_dir() . '/kadupul-data-input-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$marker = $directory . '/executed';
$payloads = array(
    '$(touch ' . $marker . ')',
    '`touch ' . $marker . '`',
    '"; touch ' . $marker . '; "',
    "'; touch " . $marker . "; '",
    'it\'s "quoted" $HOME',
);
$out = array();
try {
    foreach ($payloads as $payload) {
        $command = data_input_command($arguments['caller'], $arguments['template'], array('arg1' => $payload, 'arg2' => 'two'), array('id' => 9));
        $shell = data_input_shell_words($command);
        $out[] = array($payload, $shell, file_exists($marker));
    }
} finally {
    if (file_exists($marker)) {
        unlink($marker);
    }
    rmdir($directory);
}
echo json_encode($out);
PHP, array('caller' => $caller, 'template' => $template));

    expect($result)->toHaveCount(5);

    foreach ($result as [$payload, $shell, $executed]) {
        expect($executed)->toBeFalse($template);
        $expected = array_map(static fn(string $word): string => str_replace('{1}', $payload, $word), $words);
        expect($shell)->toBe(array('status' => 0, 'words' => $expected, 'err' => ''), $template);
    }
})->with(array('poller', 'test'))->with(array(
    'double quoted' => array('"<arg1>"', array('{1}')),
    'single quoted' => array("'<arg1>'", array('{1}')),
    'double quoted prefix' => array('"--name=<arg1>"', array('--name={1}')),
    'single quoted suffix' => array("'<arg1>.txt'", array('{1}.txt')),
    'two in one word' => array('"<arg1><arg2>"', array('{1}two')),
    'command substitution' => array('"$(printf %s <arg1>)"', array('{1}')),
    'mixed with options' => array('-x "<arg1>" \'<arg2>\' <arg2>', array('-x', '{1}', 'two', 'two')),
));

test('a field value cannot place another field into the command', function () {
    $result = data_input_substitution_run('single-pass', <<<'PHP'
$directory = sys_get_temp_dir() . '/kadupul-data-input-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$marker = $directory . '/executed';
$command = data_input_command('poller', 'cmd <arg1> <arg2>', array('arg1' => '<arg2>', 'arg2' => '; touch ' . $marker . ' #'));
$shell = data_input_shell_words($command);
$executed = file_exists($marker);
if ($executed) {
    unlink($marker);
}
rmdir($directory);
echo json_encode(array($shell, $executed, $marker));
PHP);

    [$shell, $executed, $marker] = $result;
    expect($executed)->toBeFalse();
    // The trailing cleanup still removes token-shaped text from values.
    expect($shell)->toBe(array('status' => 0, 'words' => array('cmd', '', '; touch ' . $marker . ' #'), 'err' => ''));
});

test('unsupported grammar inside a command substitution produces no command', function (string $template) {
    $result = data_input_substitution_run('rejected', <<<'PHP'
echo json_encode(array(
    data_input_command('poller', $arguments['template'], array('arg1' => 'value')),
    data_input_command('test', $arguments['template'], array('arg1' => 'value'), array('id' => 9)),
));
PHP, array('template' => $template));

    expect($result)->toBe(array('', ''));
})->with(array(
    'subshell' => array('"$( (true); printf %s <arg1>)"'),
    'case' => array('"$(case x in x) printf %s <arg1>;; esac)"'),
    'comment' => array("\"\$(printf %s # )\n<arg1>)\""),
    'redirect' => array('"$(printf %s > /dev/null; printf %s <arg1>)"'),
    'backtick subshell' => array('"`(true); printf %s <arg1>`"'),
));

test('backslashes inside backticks and fields inside ${} produce no command', function (string $caller, string $template) {
    $result = data_input_substitution_run('nested-rejected', <<<'PHP'
$directory = sys_get_temp_dir() . '/kadupul-data-input-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$marker = $directory . '/executed';
try {
    $command = data_input_command($arguments['caller'], $arguments['template'], array('arg1' => '$(touch ' . $marker . ')'), array('id' => 9));
    $shell = data_input_shell_words($command);
    $executed = file_exists($marker);
} finally {
    if (file_exists($marker)) {
        unlink($marker);
    }
    rmdir($directory);
}
echo json_encode(array($command, $executed, $shell['status']));
PHP, array('caller' => $caller, 'template' => $template));

    expect($result)->toBe(array('', false, 0));
})->with(array('poller', 'test'))->with(array(
    'escaped quotes in backticks' => array('`printf "%s" \\\\"<arg1>\\\\"`'),
    'parameter default' => array('"${X:-<arg1>}"'),
    'parameter default with prefix' => array('"${X:-x<arg1>}"'),
));

test('bash ANSI-C and locale quoting produce no command', function (string $shell, string $caller, string $template) {
    $path = $shell === 'bash' ? trim((string) shell_exec('command -v bash')) : $shell;
    if ($path === '') {
        $this->markTestSkipped('bash is not installed');
    }

    $result = data_input_substitution_run('ansi-c-rejected', <<<'PHP'
$directory = sys_get_temp_dir() . '/kadupul-data-input-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$marker = $directory . '/executed';
try {
    $command = data_input_command($arguments['caller'], $arguments['template'], array('arg1' => '$(touch ' . $marker . ')'), array('id' => 9));
    $shell = data_input_shell_words($command, $arguments['shell']);
    $executed = file_exists($marker);
} finally {
    if (file_exists($marker)) {
        unlink($marker);
    }
    rmdir($directory);
}
echo json_encode(array($command, $executed, $shell['status']));
PHP, array('caller' => $caller, 'template' => $template, 'shell' => $path));

    expect($result)->toBe(array('', false, 0));
})->with(array('/bin/sh', 'bash'))->with(array('poller', 'test'))->with(array(
    'escaped quote in ANSI-C string' => array("$'x\\'<arg1>'"),
    'ANSI-C string before a field' => array("printf %s $'a\\'b' <arg1>"),
    'locale string' => array('$"<arg1>"'),
));

test('a field directly after an unescaped dollar produces no command', function (string $shell, string $caller, string $template) {
    $path = $shell === 'bash' ? trim((string) shell_exec('command -v bash')) : $shell;
    if ($path === '') {
        $this->markTestSkipped('bash is not installed');
    }

    $result = data_input_substitution_run('dollar-rejected', <<<'PHP'
$directory = sys_get_temp_dir() . '/kadupul-data-input-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$marker = $directory . '/executed';
try {
    // A backslash and quote at the start of the value desynchronize $'...'.
    $command = data_input_command($arguments['caller'], $arguments['template'], array('arg1' => "\\'\$(touch " . $marker . ') #'), array('id' => 9));
    $shell = data_input_shell_words($command, $arguments['shell']);
    $executed = file_exists($marker);
} finally {
    if (file_exists($marker)) {
        unlink($marker);
    }
    rmdir($directory);
}
echo json_encode(array($command, $executed, $shell['status']));
PHP, array('caller' => $caller, 'template' => $template, 'shell' => $path));

    expect($result)->toBe(array('', false, 0));
})->with(array('/bin/sh', 'bash'))->with(array('poller', 'test'))->with(array(
    'bare' => array('$<arg1>'),
    'after text' => array('x$<arg1>'),
    'double quoted' => array('"$<arg1>"'),
    'single quoted' => array("'$<arg1>'"),
    'after escaped backslash' => array('\\\\$<arg1>'),
    'before a backslash' => array('$\\<arg1>'),
));

test('an escaped dollar before a field stays literal', function () {
    $result = data_input_substitution_run('dollar-escaped', <<<'PHP'
$command = data_input_command('poller', 'printf %s \\$<arg1>', array('arg1' => 'a b'));
echo json_encode(array($command, data_input_shell_words($command)));
PHP);

    expect($result[0])->toBe("printf %s \\$'a b'");
    expect($result[1]['words'])->toBe(array('printf', '%s', '$a b'));
});

test('parameter expansions without fields keep their command lines', function () {
    $result = data_input_substitution_run('param-kept', <<<'PHP'
$command = data_input_command('poller', 'printf "${X:-%s}" "<arg1>"', array('arg1' => 'a b'));
echo json_encode(array($command, data_input_shell_words($command)));
PHP);

    expect($result[0])->toBe("printf \"\${X:-%s}\" 'a b'");
    expect($result[1]['words'])->toBe(array('printf', '%s', 'a b'));
});

test('raw path settings keep the quoting the template gives them', function () {
    $result = data_input_substitution_run('paths', <<<'PHP'
$command = data_input_command('poller', '"<path_php_binary>" -q "<path_cacti>/scripts/x.php" <arg1>', array('arg1' => 'a b'));
echo json_encode(array($command, data_input_shell_words($command)));
PHP, array('base' => '/opt/kadupul base'));

    expect($result[0])->toBe("\"/usr/bin/php\" -q \"/opt/kadupul base/scripts/x.php\" 'a b'");
    expect($result[1]['words'])->toBe(array('/usr/bin/php', '-q', '/opt/kadupul base/scripts/x.php', 'a b'));
});

test('substitution matrix keeps every value literal through /bin/sh', function () {
    expect(data_input_substitution_run('matrix', 'echo json_encode(script_substitution_matrix());'))->toBe(1321);
});
