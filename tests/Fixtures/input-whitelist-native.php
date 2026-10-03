<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Full byte-identical CLI and actual SQLite queries. Only bootstrap, collector
// transport and the after-read scheduling barrier are isolated adapters.
$directory = dirname(__DIR__);
$fixture = json_decode(file_get_contents($directory . '/scenario.json'), true, flags: JSON_THROW_ON_ERROR);
$config = ['base_path' => $directory, 'poller_id' => 1, 'input_whitelist' => $fixture['path'] ?? $directory . '/whitelist.json'];
if (getenv('KADUPUL_NATIVE_WHITELIST_COVERAGE')) {
    $coverageRoot = getenv('KADUPUL_NATIVE_WHITELIST_ROOT');
    if (hash_file('sha256', __FILE__) !== hash_file('sha256', $coverageRoot . '/tests/Fixtures/input-whitelist-native.php')) {
        throw new RuntimeException('Copied bootstrap source changed');
    }
    define('RRD_TEST_COVERAGE_DIRECTORY', getenv('KADUPUL_NATIVE_WHITELIST_COVERAGE'));
    define('RRD_TEST_CLI_COVERAGE_COPY', $directory . '/cli/input_whitelist.php');
    define('RRD_TEST_CLI_COVERAGE_SOURCE', $coverageRoot . '/cli/input_whitelist.php');
    define('INPUT_WHITELIST_TEST_COVERAGE', true);
    define('INPUT_WHITELIST_NATIVE_SCENARIO', json_encode([array_slice($_SERVER['argv'], 1), $fixture], JSON_THROW_ON_ERROR));
    require $coverageRoot . '/tests/Fixtures/rrd-process-coverage.php';
    register_shutdown_function(static function () {
        define('INPUT_WHITELIST_NATIVE_COMPLETED', ['actual-cli-terminated']);
    });
}
if (isset($fixture['runAs'])) {
    if (!posix_setgid($fixture['runAs']) || !posix_setuid($fixture['runAs'])) {
        throw new RuntimeException('Cannot select native worker identity');
    }
}
$db = new PDO('sqlite:' . $directory . '/database.sqlite', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
function cacti_sizeof($value)
{
    return is_array($value) ? count($value) : 0;
}
function db_fetch_cell_prepared($sql, $params)
{
    $query = $GLOBALS['db']->prepare($sql);
    $query->execute($params);
    return $query->fetchColumn();
}
final class WhitelistScheduledRow implements ArrayAccess
{
    public function __construct(private array $row) {}
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->row[$offset]);
    }
    public function offsetGet(mixed $offset): mixed
    {
        if (($GLOBALS['fixture']['hold'] ?? false) && $offset === 'hash' && !file_exists($GLOBALS['directory'] . '/held')) {
            touch($GLOBALS['directory'] . '/held');
            $deadline = hrtime(true) / 1e9 + 10;
            while (!file_exists($GLOBALS['directory'] . '/release')) {
                if (hrtime(true) / 1e9 > $deadline) {
                    throw new RuntimeException('Scheduling barrier expired');
                }
                usleep(10000);
            }
        }
        return $this->row[$offset];
    }
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('Read-only SQL record');
    }
    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('Read-only SQL record');
    }
}
function db_fetch_assoc($sql)
{
    $rows = $GLOBALS['db']->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    if (!file_exists($GLOBALS['directory'] . '/queried')) {
        touch($GLOBALS['directory'] . '/queried');
    }
    return array_map(static fn($row) => new WhitelistScheduledRow($row), $rows);
}
function push_out_data_input_method($id)
{
    // Transport boundary verifies that propagation owns no whitelist lock.
    $lock = fopen($GLOBALS['config']['input_whitelist'] . '.lock', 'c+b');
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Collector called while whitelist locked');
    }
    flock($lock, LOCK_UN);
    fclose($lock);
    file_put_contents($GLOBALS['directory'] . '/pushes', $id . "\n", FILE_APPEND);
}
if ($fixture['limit'] ?? false) {
    pcntl_signal(SIGXFSZ, SIG_IGN);
    posix_setrlimit(POSIX_RLIMIT_FSIZE, 128, 128);
}

if (in_array('--audit', $_SERVER['argv'], true)) {
    function db_fetch_row_prepared($sql, $params)
    {
        $query = $GLOBALS['db']->prepare($sql);
        $query->execute($params);
        return $query->fetch(PDO::FETCH_ASSOC);
    }
    require getenv('KADUPUL_NATIVE_WHITELIST_ROOT') . '/lib/template.php';
}
