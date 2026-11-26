<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Security;

use PHPUnit\Framework\TestCase;

final class OpsCliAndScriptsHardeningTest extends TestCase
{
    private string $repoRoot;

    /** @var list<string> */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 3);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->tempDirs) as $dir) {
            $this->removeTree($dir);
        }
        $this->tempDirs = [];
    }

    public function testDockerfileAndRuntimeConfigsHardenOwnershipErrorsAndTimeouts(): void
    {
        $dockerfile = file_get_contents($this->repoRoot . '/Dockerfile');
        $phpIni = file_get_contents($this->repoRoot . '/docker/php.ini');
        $phpFpm = file_get_contents($this->repoRoot . '/docker/php-fpm.conf');

        $this->assertIsString($dockerfile);
        $this->assertIsString($phpIni);
        $this->assertIsString($phpFpm);

        // S11-06: immutable codebase owned by root:root with non-owner write bits cleared
        $this->assertStringNotContainsString('--chown=www-data:www-data', $dockerfile);
        $this->assertStringContainsString('COPY --chown=root:root . .', $dockerfile);
        $this->assertStringContainsString('chmod -R go-w /var/www/html', $dockerfile);
        $this->assertStringContainsString('cache:warmup --no-debug', $dockerfile);
        $this->assertStringContainsString('chown -R www-data:www-data cache log rra var', $dockerfile);

        // S11-07: production php.ini baseline, display_errors disabled, bounded timeouts
        $this->assertStringContainsString('cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini', $dockerfile);
        $this->assertMatchesRegularExpression('/^\s*display_errors\s*=\s*Off\s*$/m', $phpIni);
        $this->assertMatchesRegularExpression('/^\s*display_startup_errors\s*=\s*Off\s*$/m', $phpIni);
        $this->assertMatchesRegularExpression('/^\s*log_errors\s*=\s*On\s*$/m', $phpIni);
        $this->assertMatchesRegularExpression('/^\s*max_execution_time\s*=\s*([1-9][0-9]*)\s*$/m', $phpIni);
        $this->assertDoesNotMatchRegularExpression('/^\s*max_execution_time\s*=\s*0\s*$/m', $phpIni);
        $this->assertMatchesRegularExpression('/^\s*request_terminate_timeout\s*=\s*[1-9][0-9]*s?\s*$/m', $phpFpm);
    }

    public function testEntrypointPassesDatabasePasswordViaMysqlPwdNotArgv(): void
    {
        $entrypointPath = $this->repoRoot . '/docker/entrypoint.sh';
        $entrypoint = file_get_contents($entrypointPath);
        $this->assertIsString($entrypoint);
        $this->assertStringNotContainsString('--password=', $entrypoint);
        $this->assertStringContainsString('MYSQL_PWD="${KADUPUL_DB_PASSWORD:-}" mysqladmin ping', $entrypoint);

        $tmp = $this->makeTempDir('entrypoint-');
        $binDir = $tmp . '/bin';
        mkdir($binDir, 0700, true);

        $argvLog = $tmp . '/mysqladmin.argv';
        $envLog = $tmp . '/mysqladmin.env';
        $secret = 's3cr3t-db-pass-98765';

        file_put_contents(
            $binDir . '/mysqladmin',
            "#!/bin/sh\nprintf '%s\\n' \"\$@\" > " . escapeshellarg($argvLog)
            . "\nprintf '%s' \"\${MYSQL_PWD:-}\" > " . escapeshellarg($envLog)
            . "\nexit 0\n"
        );
        chmod($binDir . '/mysqladmin', 0700);

        file_put_contents($binDir . '/php-fpm', "#!/bin/sh\nexit 0\n");
        chmod($binDir . '/php-fpm', 0700);

        $result = $this->runProcess(
            ['/bin/sh', $entrypointPath, 'web'],
            [
                'PATH' => $binDir . ':/usr/bin:/bin',
                'KADUPUL_DB_HOST' => '127.0.0.1',
                'KADUPUL_DB_PORT' => '3306',
                'KADUPUL_DB_USER' => 'kadupul',
                'KADUPUL_DB_PASSWORD' => $secret,
                'KADUPUL_DB_WAIT' => '2',
            ]
        );

        $this->assertSame(0, $result['exit'], $result['stderr']);
        $this->assertFileExists($argvLog);
        $this->assertFileExists($envLog);
        $this->assertStringNotContainsString($secret, (string) file_get_contents($argvLog));
        $this->assertStringNotContainsString('--password', (string) file_get_contents($argvLog));
        $this->assertSame($secret, (string) file_get_contents($envLog));
    }

    public function testStructureRraPathsRejectsSymlinksAndTraversesAllEntries(): void
    {
        $tmp = $this->makeTempDir('structure-rra-');
        foreach (['/cli', '/include', '/lib', '/rra', '/outside'] as $sub) {
            mkdir($tmp . $sub, 0700, true);
        }

        copy($this->repoRoot . '/cli/structure_rra_paths.php', $tmp . '/cli/structure_rra_paths.php');
        symlink($this->repoRoot . '/lib/rrd_maintenance.php', $tmp . '/lib/rrd_maintenance.php');
        file_put_contents($tmp . '/rra/legacy_10.rrd', 'RRD-LEGACY');
        file_put_contents($tmp . '/rra/good_11.rrd', 'RRD-GOOD');
        symlink($tmp . '/outside', $tmp . '/rra/10');

        $dbLog = $tmp . '/db_updates.json';
        $fixtureLibRrd = <<<'PHP'
<?php
function rrdtool_uses_proxy() { return false; }
function cacti_path_is_within($candidate, $base) {
    $baseReal = realpath($base);
    if ($baseReal === false) { return false; }
    $candReal = realpath($candidate);
    if ($candReal === false) {
        $parent = realpath(dirname($candidate));
        if ($parent === false) { return false; }
        $candReal = rtrim($parent, '/') . '/' . basename($candidate);
    }
    return $candReal === $baseReal || str_starts_with($candReal, rtrim($baseReal, '/') . '/');
}
function validate_relative_path_within($path, $base_dir) {
    if (!is_string($path) || $path === '' || str_contains($path, "\0")) { return false; }
    $baseReal = realpath($base_dir);
    if ($baseReal === false) { return false; }
    $walk = $baseReal;
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.' || $part === '..') { return false; }
        $walk .= '/' . $part;
        if (is_link($walk)) { return false; }
    }
    return $walk;
}
function rrd_check_path($path, $rra_base = null, $logopt = null) {
    $root = $rra_base ?? ($GLOBALS['config']['rra_path'] ?? '');
    if (!is_string($path) || $path === '' || !is_string($root) || $root === '') { return false; }
    $rootReal = realpath($root);
    if ($rootReal === false) { return false; }
    $base = rtrim($rootReal, '/') . '/';
    if (!str_starts_with($path, $base)) { return false; }
    return validate_relative_path_within(substr($path, strlen($base)), $rootReal) !== false;
}
function rrdtool_ownership_path($path, $root = null, $logopt = 'POLLER') {
    clearstatcache(true);
    if ($root === null) { return is_link($path) ? false : realpath($path); }
    $base = rtrim($root, '/') . '/';
    $realRoot = realpath($root);
    if ($realRoot === false || !str_starts_with($path, $base)) { return false; }
    $checked = validate_relative_path_within(substr($path, strlen($base)), $root);
    if ($checked === false) { return false; }
    $realPath = realpath($checked);
    if ($realPath === false || $realPath !== $checked || !str_starts_with($realPath, rtrim($realRoot, '/') . '/')) {
        return false;
    }
    return $realPath;
}
PHP;
        file_put_contents($tmp . '/lib/rrd.php', $fixtureLibRrd);

        $cliCheck = <<<'PHP'
<?php
$config = array(
    'base_path' => dirname(__DIR__),
    'rra_path' => getenv('TEST_RRA_PATH') ?: (dirname(__DIR__) . '/rra'),
    'poller_id' => 1,
    'cacti_server_os' => 'unix',
);
$debug = false;
function read_config_option($name) {
    if ($name === 'storage_location') { return 0; }
    if ($name === 'boost_rrd_update_enable') { return 'on'; }
    if ($name === 'extended_paths_type') { return 'device'; }
    if ($name === 'extended_paths_hashes') { return 100; }
    return '';
}
function set_config_option($name, $value) {}
function cacti_sizeof($v) { return is_array($v) ? count($v) : 0; }
function cacti_log(...$args) {}
function clean_up_file_name($s) { return preg_replace('/[^a-zA-Z0-9_-]/', '_', $s); }
function get_cacti_cli_version() { return '1.3.0'; }
function db_fetch_cell($sql) { return 1; }
function db_fetch_cell_prepared($sql, $params = array()) { return 'ds'; }
function db_execute_prepared($sql, $params = array()) {
    $file = dirname(__DIR__) . '/db_updates.json';
    $existing = file_exists($file) ? json_decode((string) file_get_contents($file), true) : array();
    $existing[] = array('sql' => $sql, 'params' => $params);
    file_put_contents($file, json_encode($existing));
    return true;
}
function db_fetch_assoc_prepared($sql, $params = array()) {
    $base = realpath($GLOBALS['config']['rra_path']);
    return array(
        array(
            'local_data_id' => 10,
            'hash_id' => 10,
            'host_id' => 10,
            'data_source_path' => '<path_rra>/legacy_10.rrd',
            'snmp_query_id' => 0,
            'description' => 'host10',
            'new_data_source_path' => '<path_rra>/10/10.rrd',
            'rrd_path' => $base . '/legacy_10.rrd',
            'new_rrd_path' => $base . '/10/10.rrd',
        ),
        array(
            'local_data_id' => 11,
            'hash_id' => 11,
            'host_id' => 11,
            'data_source_path' => '<path_rra>/good_11.rrd',
            'snmp_query_id' => 0,
            'description' => 'host11',
            'new_data_source_path' => '<path_rra>/11/11.rrd',
            'rrd_path' => $base . '/good_11.rrd',
            'new_rrd_path' => $base . '/11/11.rrd',
        ),
    );
}
PHP;
        file_put_contents($tmp . '/include/cli_check.php', $cliCheck);

        // Case 1: symlinked base_rra_path is refused before touching any files
        symlink($tmp . '/rra', $tmp . '/rra-link');
        $untrusted = $this->runProcess(
            [PHP_BINARY, $tmp . '/cli/structure_rra_paths.php', '--proceed'],
            ['TEST_RRA_PATH' => $tmp . '/rra-link']
        );
        $this->assertNotSame(0, $untrusted['exit']);
        $this->assertStringContainsString('not a trusted directory', $untrusted['stdout']);

        // Case 2: planted symlink destination (rra/10 -> outside) is skipped while valid rra/11 succeeds
        $run = $this->runProcess(
            [PHP_BINARY, $tmp . '/cli/structure_rra_paths.php', '--proceed'],
            ['TEST_RRA_PATH' => $tmp . '/rra']
        );
        $this->assertSame(0, $run['exit'], $run['stdout'] . $run['stderr']);
        $this->assertStringContainsString('is outside the RRA directory or is a symlink, Skipping', $run['stdout']);
        $this->assertFileDoesNotExist($tmp . '/outside/10.rrd');
        $this->assertFileExists($tmp . '/rra/legacy_10.rrd');
        $this->assertFileExists($tmp . '/rra/11/11.rrd');
        $this->assertFileDoesNotExist($tmp . '/rra/good_11.rrd');
        $this->assertFileExists($dbLog);

        // Case 3: sp_recursive_chown/chgrp reject symlinks and traverse all entries without early return
        $source = (string) file_get_contents($this->repoRoot . '/cli/structure_rra_paths.php');
        $this->assertStringNotContainsString('glob(', $source);
        $this->assertStringContainsString('new DirectoryIterator(', $source);
        $this->assertStringContainsString('lchown(', $source);
        $this->assertStringContainsString('lchgrp(', $source);

        $probeCode = <<<'PHP'
<?php
require $argv[1] . '/lib/rrd.php';
$src = file_get_contents($argv[1] . '/cli/structure_rra_paths.php');
$pos = strpos($src, 'function sp_recursive_chown');
eval(substr($src, $pos));
$root = realpath($argv[1] . '/rra');
$dir = $root . '/multi';
mkdir($dir . '/sub', 0700, true);
file_put_contents($dir . '/a.rrd', 'a');
file_put_contents($dir . '/b.rrd', 'b');
file_put_contents($dir . '/sub/c.rrd', 'c');
$uid = posix_geteuid();
$gid = posix_getegid();
$ok1 = sp_recursive_chown($dir, $uid, $root);
$ok2 = sp_recursive_chgrp($dir, $gid, $root);
symlink($argv[1] . '/outside', $dir . '/evil-link');
$bad1 = sp_recursive_chown($dir, $uid, $root);
$bad2 = sp_recursive_chgrp($dir, $gid, $root);
echo json_encode(array('ok1' => $ok1, 'ok2' => $ok2, 'bad1' => $bad1, 'bad2' => $bad2));
PHP;
        $probeFile = $tmp . '/probe_chown.php';
        file_put_contents($probeFile, $probeCode);
        $probe = $this->runProcess([PHP_BINARY, $probeFile, $tmp]);
        $this->assertSame(0, $probe['exit'], $probe['stderr']);
        $decoded = json_decode($probe['stdout'], true);
        $this->assertSame(['ok1' => true, 'ok2' => true, 'bad1' => false, 'bad2' => false], $decoded);
    }

    public function testRemoveDeviceRejectsInvalidIdsAndUsesPreparedStatementsAndSafeRegex(): void
    {
        $tmp = $this->makeTempDir('remove-device-');
        foreach (['/cli', '/include', '/lib'] as $sub) {
            mkdir($tmp . $sub, 0700, true);
        }

        copy($this->repoRoot . '/cli/remove_device.php', $tmp . '/cli/remove_device.php');
        foreach (['api_automation_tools.php', 'api_data_source.php', 'api_device.php', 'api_graph.php', 'data_query.php', 'snmp.php', 'poller.php', 'utility.php'] as $lib) {
            file_put_contents($tmp . '/lib/' . $lib, "<?php\n");
        }

        $queryLog = $tmp . '/queries.json';
        $cliCheck = <<<'PHP'
<?php
$config = array('base_path' => dirname(__DIR__), 'poller_id' => 1);
function cacti_sizeof($v) { return is_array($v) ? count($v) : 0; }
function get_cacti_cli_version() { return '1.3.0'; }
function getHostsByDescription() {
    return array('router/1' => 42, 'core-switch' => 43);
}
function getAddresses() {
    return array('10.0.0.1' => 42, '10.0.0.2' => 43);
}
function db_fetch_assoc($sql) {
    fwrite(STDERR, "UNPREPARED_SQL_CALLED:$sql\n");
    exit(99);
}
function db_fetch_assoc_prepared($sql, $params = array()) {
    $file = dirname(__DIR__) . '/queries.json';
    file_put_contents($file, json_encode(array('sql' => $sql, 'params' => $params)));
    return array(
        array('id' => 42, 'hostname' => '10.0.0.1', 'description' => 'router/1'),
    );
}
function api_device_remove_multi($ids) { return true; }
function is_error_message() { return false; }
function cacti_log(...$args) {}
PHP;
        file_put_contents($tmp . '/include/cli_check.php', $cliCheck);

        // SQLi payload in --id must be rejected before any query executes
        foreach (['1,2 OR 1=1', '0', '-5', '1,,2', 'abc', ''] as $badId) {
            $res = $this->runProcess([PHP_BINARY, $tmp . '/cli/remove_device.php', '--id=' . $badId]);
            $this->assertSame(1, $res['exit'], "Expected failure for --id=$badId: " . $res['stdout'] . $res['stderr']);
            $this->assertStringContainsString('ERROR: Invalid Device ID', $res['stdout']);
            $this->assertFileDoesNotExist($queryLog);
        }

        // Invalid regex in --description must fail cleanly without PHP warning
        $badRegex = $this->runProcess([PHP_BINARY, $tmp . '/cli/remove_device.php', '--description=[unclosed']);
        $this->assertSame(1, $badRegex['exit']);
        $this->assertStringContainsString('ERROR: Invalid regular expression', $badRegex['stdout']);
        $this->assertSame('', trim($badRegex['stderr']));

        // Forward slash in --description must be safely delimited and match 'router/1'
        $slashDesc = $this->runProcess([PHP_BINARY, $tmp . '/cli/remove_device.php', '--description=router/1']);
        $this->assertSame(0, $slashDesc['exit'], $slashDesc['stdout'] . $slashDesc['stderr']);
        $this->assertFileExists($queryLog);
        $logged = json_decode((string) file_get_contents($queryLog), true);
        $this->assertSame([42], $logged['params']);
        $this->assertStringContainsString('WHERE id IN (?)', $logged['sql']);
        unlink($queryLog);

        // Valid comma-separated IDs are normalized to unique ints and bound via prepared placeholders
        $valid = $this->runProcess([PHP_BINARY, $tmp . '/cli/remove_device.php', '--id=10,20,10']);
        $this->assertSame(0, $valid['exit'], $valid['stdout'] . $valid['stderr']);
        $logged = json_decode((string) file_get_contents($queryLog), true);
        $this->assertSame([10, 20], $logged['params']);
        $this->assertStringContainsString('WHERE id IN (?, ?)', $logged['sql']);
    }

    public function testSpliceRrdAvoidsShellExecAndUsesParameterizedSqliteStatements(): void
    {
        $source = (string) file_get_contents($this->repoRoot . '/cli/splice_rrd.php');
        $this->assertStringNotContainsString('shell_exec(', $source);
        $this->assertStringContainsString('rrd_maintenance_run_command(array($rrdtool)', $source);
        $this->assertStringContainsString('$stmt->bindValue(', $source);

        $tmp = $this->makeTempDir('splice-rrd-');

        // Test 1: direct-argv version probe never invokes a shell even if path_rrdtool has shell metacharacters
        foreach (['/cli', '/include', '/lib', '/rra'] as $sub) {
            mkdir($tmp . $sub, 0700, true);
        }
        copy($this->repoRoot . '/cli/splice_rrd.php', $tmp . '/cli/splice_rrd.php');
        symlink($this->repoRoot . '/lib/rrd_maintenance.php', $tmp . '/lib/rrd_maintenance.php');
        symlink($this->repoRoot . '/src', $tmp . '/src');
        symlink($this->repoRoot . '/include/vendor', $tmp . '/include/vendor');

        $marker = $tmp . '/shell-executed';
        $evilBin = $tmp . '/fake;touch ' . basename($marker);
        file_put_contents($evilBin, "#!/bin/sh\nexit 0\n");
        chmod($evilBin, 0700);
        file_put_contents($tmp . '/rra/old.rrd', 'stub');
        file_put_contents($tmp . '/rra/new.rrd', 'stub');

        $cliCheck = <<<'PHP'
<?php
$config = array(
    'base_path' => dirname(__DIR__),
    'rra_path' => dirname(__DIR__) . '/rra',
    'poller_id' => 1,
    'cacti_server_os' => 'unix',
);
function cacti_sizeof($v) { return is_array($v) ? count($v) : 0; }
function read_config_option($name) {
    return $name === 'path_rrdtool' ? getenv('TEST_RRDTOOL_BIN') : '';
}
PHP;
        file_put_contents($tmp . '/include/cli_check.php', $cliCheck);

        $run = $this->runProcess(
            [PHP_BINARY, $tmp . '/cli/splice_rrd.php', '--oldrrd=' . $tmp . '/rra/old.rrd', '--newrrd=' . $tmp . '/rra/new.rrd'],
            ['TEST_RRDTOOL_BIN' => $evilBin],
            $tmp
        );
        $this->assertNotSame(0, $run['exit']);
        $this->assertFileDoesNotExist($marker);

        // Test 2: parameterized SQLite3 loadTable() and getOldRRDValue() safely handle SQL metacharacters
        $sqliteProbe = <<<'PHP'
<?php
$src = file_get_contents($argv[1]);
$extract = static function (string $src, string $fnName): string {
    $start = strpos($src, 'function ' . $fnName . '(');
    $brace = strpos($src, '{', $start);
    $depth = 0;
    $len = strlen($src);
    for ($i = $brace; $i < $len; $i++) {
        if ($src[$i] === '{') { $depth++; }
        elseif ($src[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($src, $start, $i - $start + 1);
            }
        }
    }
    throw new RuntimeException('Failed to extract ' . $fnName);
};
eval($extract($src, 'createTable'));
eval($extract($src, 'loadTable'));
eval($extract($src, 'getOldRRDValue'));
$use_db = true;
$db = createTable();
$maliciousCf = "AVERAGE' OR '1'='1";
$records = array(
    0 => array(
        'AVERAGE' => array(1700000100 => 12.5),
        $maliciousCf => array(1700000200 => 99.25),
    ),
);
loadTable($db, $records);
$empty = array();
$resNormal = getOldRRDValue($empty, 0, 'AVERAGE', 1700000200);
$resMalicious = getOldRRDValue($empty, 0, $maliciousCf, 1700000200);
$resProbeInjection = getOldRRDValue($empty, 0, "NOPE' OR '1'='1", 1700000200);
echo json_encode(array(
    'normal' => $resNormal,
    'malicious' => $resMalicious,
    'injection' => $resProbeInjection,
));
PHP;
        $sqliteProbeFile = $tmp . '/sqlite_probe.php';
        file_put_contents($sqliteProbeFile, $sqliteProbe);
        $probeRun = $this->runProcess([PHP_BINARY, $sqliteProbeFile, $this->repoRoot . '/cli/splice_rrd.php']);
        $this->assertSame(0, $probeRun['exit'], $probeRun['stderr']);
        $decoded = json_decode($probeRun['stdout'], true);
        $this->assertSame(['normal' => 12.5, 'malicious' => 99.25, 'injection' => 'NaN'], $decoded);
    }

    public function testLegacyScriptsBlockArgumentInjectionTraversalAndRegexInjection(): void
    {
        // scripts/webhits.pl: rejects outside /var/log, traversal, leading dash, metachars
        foreach (['/etc/passwd', '/var/log/../etc/passwd', '-l', '/var/log/a;id', '/var/log/*'] as $arg) {
            $res = $this->runProcess(['perl', $this->repoRoot . '/scripts/webhits.pl', $arg]);
            $this->assertSame(0, $res['exit'], "webhits.pl failed for $arg: " . $res['stderr']);
            $this->assertSame('', $res['stdout'], "webhits.pl leaked output for $arg");
            $this->assertSame('', $res['stderr'], "webhits.pl emitted stderr for $arg");
        }

        // scripts/diskfree.pl: rejects leading dash and handles regex metacharacters cleanly
        foreach (['-h', '--help', 'a(b[c', '/nonexistent;id'] as $arg) {
            $res = $this->runProcess(['perl', $this->repoRoot . '/scripts/diskfree.pl', $arg]);
            $this->assertSame(0, $res['exit'], "diskfree.pl failed for $arg: " . $res['stderr']);
            $this->assertSame('', $res['stdout'], "diskfree.pl emitted unexpected output for $arg");
            $this->assertStringNotContainsString('Unmatched (', $res['stderr']);
        }

        // scripts/ping.pl: rejects leading dash and metacharacters immediately with 'U'
        foreach (['-c', '--help', '-f', '127.0.0.1;id', ''] as $arg) {
            $res = $this->runProcess(['perl', $this->repoRoot . '/scripts/ping.pl', $arg]);
            $this->assertSame(0, $res['exit'], "ping.pl failed for $arg: " . $res['stderr']);
            $this->assertSame('U', $res['stdout'], "ping.pl did not return U for $arg");
            $this->assertSame('', $res['stderr'], "ping.pl emitted stderr for $arg");
        }

        // scripts/diskfree.sh: rejects empty and leading-dash args
        foreach (['', '-h', '--help'] as $arg) {
            $res = $this->runProcess(['/bin/sh', $this->repoRoot . '/scripts/diskfree.sh', $arg]);
            $this->assertSame(0, $res['exit']);
            $this->assertSame('', $res['stdout']);
        }

        // scripts/linux_memory.pl: rejects invalid args and never shells out
        foreach (['-E', 'MemTotal;id', 'a(b'] as $arg) {
            $res = $this->runProcess(['perl', $this->repoRoot . '/scripts/linux_memory.pl', $arg]);
            $this->assertSame(0, $res['exit']);
            $this->assertSame('', $res['stdout']);
            $this->assertSame('', $res['stderr']);
        }
    }

    public function testNetSnmpDiskScriptsUsePrivateStateDirRefuseSymlinksAndWriteAtomically(): void
    {
        foreach (['ss_net_snmp_disk_bytes.php', 'ss_net_snmp_disk_io.php'] as $script) {
            $source = (string) file_get_contents($this->repoRoot . '/scripts/' . $script);
            $this->assertStringNotContainsString('sys_get_temp_dir(', $source, "$script must not use sys_get_temp_dir()");
            $this->assertStringNotContainsString('0777', $source, "$script must not use 0777 permissions");
        }

        $tmp = $this->makeTempDir('net-snmp-disk-');
        mkdir($tmp . '/cache', 0700, true);
        $outsideFile = $tmp . '/outside-target.txt';
        file_put_contents($outsideFile, 'PROTECTED');

        $probe = <<<'PHP'
<?php
$repoRoot = $argv[1];
$basePath = $argv[2];
$outsideFile = $argv[3];

$called_by_script_server = true;
$config = array(
    'base_path' => $basePath,
    'include_path' => $repoRoot . '/include',
    'php_snmp_support' => false,
);
$environ = 'poller';
$poller_id = 1;

function db_fetch_cell_prepared($sql, $params = array()) {
    return '';
}

require_once $repoRoot . '/include/vendor/autoload.php';
require_once $repoRoot . '/scripts/ss_net_snmp_disk_bytes.php';
require_once $repoRoot . '/scripts/ss_net_snmp_disk_io.php';

$invalidHostBytes = ss_net_snmp_disk_bytes('../etc/passwd');
$invalidZeroBytes = ss_net_snmp_disk_bytes('0');
$invalidHostIo = ss_net_snmp_disk_io('-5');
$invalidZeroIo = ss_net_snmp_disk_io('0');

$bytesDir = ss_net_snmp_disk_bytes_state_dir($config, 'poller');
$ioDir = ss_net_snmp_disk_io_state_dir($config, 'realtime');

$bytesMode = $bytesDir !== false ? (lstat($bytesDir)['mode'] & 0777) : -1;
$ioMode = $ioDir !== false ? (lstat($ioDir)['mode'] & 0777) : -1;

$writeBytes = ss_net_snmp_disk_bytes_write_state($bytesDir, '7_bytes', json_encode(array('uptime' => 100, 'br1' => 500)));
$readBytes = ss_net_snmp_disk_bytes_read_state($bytesDir, '7_bytes');
$fileMode = lstat($bytesDir . '/7_bytes')['mode'] & 0777;

$writeIo = ss_net_snmp_disk_io_write_state($ioDir, '7_1_io_rt', json_encode(array('uptime' => 200, 'dr1' => 10)));
$readIo = ss_net_snmp_disk_io_read_state($ioDir, '7_1_io_rt');

// Plant symlinks at state file paths and verify both read and write refuse them
symlink($outsideFile, $bytesDir . '/9_bytes');
$symReadBytes = ss_net_snmp_disk_bytes_read_state($bytesDir, '9_bytes');
$symWriteBytes = ss_net_snmp_disk_bytes_write_state($bytesDir, '9_bytes', '{"poison":1}');

symlink($outsideFile, $ioDir . '/9_1_io_rt');
$symReadIo = ss_net_snmp_disk_io_read_state($ioDir, '9_1_io_rt');
$symWriteIo = ss_net_snmp_disk_io_write_state($ioDir, '9_1_io_rt', '{"poison":1}');

// Reject invalid filenames / traversal
$badWriteBytes = ss_net_snmp_disk_bytes_write_state($bytesDir, '../evil_bytes', '{}');
$badWriteIo = ss_net_snmp_disk_io_write_state($ioDir, '-1_io', '{}');

// Reject symlinked state directory
$symBase = $basePath . '/sym-app';
mkdir($symBase . '/cache', 0700, true);
symlink($basePath, $symBase . '/cache/net_snmp_disk');
$symDirBytes = ss_net_snmp_disk_bytes_state_dir(array('base_path' => $symBase), 'poller');
$symDirIo = ss_net_snmp_disk_io_state_dir(array('base_path' => $symBase), 'poller');

echo json_encode(array(
    'invalidHostBytes' => $invalidHostBytes,
    'invalidZeroBytes' => $invalidZeroBytes,
    'invalidHostIo' => $invalidHostIo,
    'invalidZeroIo' => $invalidZeroIo,
    'bytesDir' => $bytesDir,
    'ioDir' => $ioDir,
    'bytesMode' => $bytesMode,
    'ioMode' => $ioMode,
    'fileMode' => $fileMode,
    'writeBytes' => $writeBytes,
    'readBytes' => $readBytes,
    'writeIo' => $writeIo,
    'readIo' => $readIo,
    'symReadBytes' => $symReadBytes,
    'symWriteBytes' => $symWriteBytes,
    'symReadIo' => $symReadIo,
    'symWriteIo' => $symWriteIo,
    'badWriteBytes' => $badWriteBytes,
    'badWriteIo' => $badWriteIo,
    'symDirBytes' => $symDirBytes,
    'symDirIo' => $symDirIo,
    'outsideContents' => file_get_contents($outsideFile),
));
PHP;

        $probeFile = $tmp . '/probe_snmp_disk.php';
        file_put_contents($probeFile, $probe);
        $run = $this->runProcess([PHP_BINARY, $probeFile, $this->repoRoot, $tmp, $outsideFile]);
        $this->assertSame(0, $run['exit'], $run['stderr']);

        $data = json_decode($run['stdout'], true);
        $this->assertIsArray($data, 'Unexpected probe output: ' . $run['stdout'] . $run['stderr']);
        $this->assertSame('reads:0 writes:0', $data['invalidHostBytes']);
        $this->assertSame('reads:0 writes:0', $data['invalidZeroBytes']);
        $this->assertSame('reads:0 writes:0', $data['invalidHostIo']);
        $this->assertSame('reads:0 writes:0', $data['invalidZeroIo']);
        $this->assertSame($tmp . '/cache/net_snmp_disk/poller', $data['bytesDir']);
        $this->assertSame($tmp . '/cache/net_snmp_disk/realtime', $data['ioDir']);
        $this->assertSame(0700, $data['bytesMode']);
        $this->assertSame(0700, $data['ioMode']);
        $this->assertSame(0600, $data['fileMode']);
        $this->assertTrue($data['writeBytes']);
        $this->assertSame(['uptime' => 100, 'br1' => 500], $data['readBytes']);
        $this->assertTrue($data['writeIo']);
        $this->assertSame(['uptime' => 200, 'dr1' => 10], $data['readIo']);
        $this->assertNull($data['symReadBytes']);
        $this->assertFalse($data['symWriteBytes']);
        $this->assertNull($data['symReadIo']);
        $this->assertFalse($data['symWriteIo']);
        $this->assertFalse($data['badWriteBytes']);
        $this->assertFalse($data['badWriteIo']);
        $this->assertFalse($data['symDirBytes']);
        $this->assertFalse($data['symDirIo']);
        $this->assertSame('PROTECTED', $data['outsideContents']);
    }

    /**
     * @param list<string>          $command
     * @param array<string, string> $env
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runProcess(array $command, array $env = [], ?string $cwd = null): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $mergedEnv = array_merge(getenv(), $env);
        $proc = proc_open($command, $descriptors, $pipes, $cwd ?? $this->repoRoot, $mergedEnv);
        $this->assertIsResource($proc);

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function makeTempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(8));
        mkdir($dir, 0700, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $entries = scandir($path);
        if (is_array($entries)) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $this->removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
