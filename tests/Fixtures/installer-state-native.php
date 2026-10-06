<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 2);
require $root . '/tests/Helpers/PhpSource.php';
require $root . '/tests/Helpers/InstallerStateEvidence.php';
require $root . '/tests/Helpers/NativeChildCoverageEvidence.php';
$coverage = null;
if (($argv[3] ?? '') === 'coverage') {
    require $root . '/tests/vendor/autoload.php';
    $filter = new SebastianBergmann\CodeCoverage\Filter();
    foreach (InstallerStateEvidence::sources() as $source) {
        if (str_starts_with($source, 'lib/')) {
            $filter->includeFile($root . '/' . $source);
        }
    }
    $coverage = new SebastianBergmann\CodeCoverage\CodeCoverage((new SebastianBergmann\CodeCoverage\Driver\Selector())->forLineCoverage($filter), $filter);
    $evidence = NativeChildCoverageEvidence::snapshot($root, InstallerStateEvidence::PRODUCER, $argv[2], InstallerStateEvidence::sources());
    $coverage->start('actual installer state ' . $argv[2]);
}
$release = file_get_contents($root . '/include/cacti_version');
if ($release === false || preg_match('/\A\d+\.\d+\.\d+\z/D', trim($release)) !== 1) {
    throw new RuntimeException('Current release is unavailable.');
}
define('CACTI_VERSION', trim($release));
define('DB_STATUS_ERROR', 0);
define('DB_STATUS_WARNING', 1);
define('DB_STATUS_RESTART', 2);
define('DB_STATUS_SUCCESS', 3);
define('DB_STATUS_SKIPPED', 4);
$directory = $argv[1];
$scenario = $argv[2];
$dsn = getenv('INSTALLER_STATE_TEST_DSN');
if ($dsn !== false && $dsn !== '') {
    if (getenv('INSTALLER_STATE_OWNED_BACKEND') !== '1' || !str_starts_with($dsn, 'mysql:')) {
        throw new RuntimeException('Native database requires an explicitly owned MySQL backend.');
    }
    $schema = 'kadupul_installer_state_' . bin2hex(random_bytes(12));
    $admin = new PDO($dsn, getenv('INSTALLER_STATE_TEST_USER') ?: '', getenv('INSTALLER_STATE_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $admin->exec('CREATE DATABASE `' . $schema . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    register_shutdown_function(static function () use ($admin, $schema, $directory): void {
        $admin->exec('DROP DATABASE `' . $schema . '`');
        if (file_put_contents($directory . '/owned-schema-cleanup.json', json_encode(['schema' => $schema, 'removed' => true], JSON_THROW_ON_ERROR)) === false) {
            throw new RuntimeException('Owned schema cleanup receipt is unavailable.');
        }
    });
    $scopedDsn = preg_replace('/;dbname=[^;]*/i', '', $dsn);
    if ($scopedDsn === null) {
        throw new RuntimeException('Owned schema connection cannot be constructed.');
    }
    $scopedDsn .= ';dbname=' . $schema;
    $reader = new PDO($scopedDsn, getenv('INSTALLER_STATE_TEST_USER') ?: '', getenv('INSTALLER_STATE_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $writer = new PDO($scopedDsn, getenv('INSTALLER_STATE_TEST_USER') ?: '', getenv('INSTALLER_STATE_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $sql = file_get_contents($root . '/cacti.sql');
    if ($sql === false) {
        throw new RuntimeException('Canonical schema is unavailable.');
    }
    foreach (['settings', 'version'] as $table) {
        if (preg_match('/CREATE TABLE ' . $table . ' \([^;]+;/s', $sql, $matches) !== 1) {
            throw new RuntimeException('Canonical installer table is unavailable.');
        }
        $reader->exec($matches[0]);
    }
} else {
    $reader = new PDO('sqlite:' . $directory . '/state.sqlite', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $writer = new PDO('sqlite:' . $directory . '/state.sqlite', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    // Canonical settings/version columns and keys from cacti.sql; no existing database.
    $reader->exec("CREATE TABLE settings(name VARCHAR(255) NOT NULL DEFAULT '' PRIMARY KEY,value VARCHAR(4096) NOT NULL DEFAULT '');
        CREATE TABLE version(cacti CHAR(20) DEFAULT '' PRIMARY KEY);");
}
$reader->prepare('INSERT INTO version(cacti) VALUES(?)')->execute([in_array($scenario, ['default', 'completed'], true) ? CACTI_VERSION : '1.2.34']);
foreach (['install_step' => '97', 'install_prev' => '96', 'install_next' => '98', 'install_theme' => 'modern', 'selected_theme' => 'modern', 'adjacent_setting' => 'unchanged'] as $name => $value) {
    $reader->prepare('INSERT INTO settings(name,value) VALUES(?,?)')->execute([$name, $value]);
}
if ($scenario === 'default') {
    $reader->exec("DELETE FROM settings WHERE name IN('install_step','install_prev','install_next')");
} elseif ($scenario === 'completed') {
    $reader->exec("UPDATE settings SET value='98' WHERE name='install_step'; UPDATE settings SET value='0' WHERE name IN('install_prev','install_next')");
    $reader->prepare('INSERT INTO settings(name,value) VALUES(?,?)')->execute(['install_version', CACTI_VERSION]);
} elseif (in_array($scenario, ['failed', 'reported-error'], true)) {
    $reader->prepare('INSERT INTO settings(name,value) VALUES(?,?)')->execute(['install_error', 'owned worker refusal']);
    if ($scenario === 'failed') $reader->exec("UPDATE settings SET value='99' WHERE name='install_step'; UPDATE settings SET value='0' WHERE name IN('install_prev','install_next')");
} elseif ($scenario === 'numeric-string') {
    $reader->exec("UPDATE settings SET value='097' WHERE name='install_step'");
}
$config = ['is_web' => false, 'base_path' => $root];
$settings = [];
$database_hostname = 'native';
$database_port = '0';
$database_default = 'owned';
$database_sessions = ['native:0:owned' => $reader];
$local_db_cnn_id = $reader;
$interleave = null;
$writes = [];
function db_fetch_cell(string $sql): mixed
{
    return $GLOBALS['reader']->query($sql)->fetchColumn();
}
function db_fetch_row_prepared(string $sql, array $parameters, bool $log = false): array
{
    $query = $GLOBALS['reader']->prepare($sql);
    $query->execute($parameters);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    $query->closeCursor();
    if ($parameters === ['install_step'] && $GLOBALS['interleave'] !== null && str_ends_with($GLOBALS['scenario'], '-read')) {
        $callback = $GLOBALS['interleave'];
        $GLOBALS['interleave'] = null;
        $callback();
    }
    return $row ?: [];
}
function db_execute_prepared(string $sql, array $parameters, bool $log = false, ?PDO $connection = null): bool
{
    $GLOBALS['writes'][] = $parameters;
    return ($connection ?? $GLOBALS['reader'])->prepare($sql)->execute($parameters);
}
function __(string $text, mixed ...$arguments): string
{
    return $arguments ? sprintf($text, ...$arguments) : $text;
}
function log_install_high(mixed ...$arguments): void {}
function log_install_medium(mixed ...$arguments): void {}
function log_install_debug(mixed ...$arguments): void
{
    if ($GLOBALS['interleave'] !== null && str_ends_with($GLOBALS['scenario'], '-write') && ($arguments[0] ?? '') === 'step' && str_starts_with($arguments[1] ?? '', 'setStep(): ')) {
        $callback = $GLOBALS['interleave'];
        $GLOBALS['interleave'] = null;
        $callback();
    }
}
function get_installed_locales(): array
{
    return [];
}
require $root . '/lib/functions.php';
$installSource = file_get_contents($root . '/install/functions.php');
if ($installSource === false) {
    throw new RuntimeException('Installer setting source is unavailable.');
}
eval(test_php_function_source($installSource, 'set_install_config_option'));
require $root . '/lib/installer.php';
$worker = (new ReflectionClass(Installer::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(Installer::class, 'stepError'))->setValue($worker, false);
$publish = Closure::bind(function (int $step): void {
    $this->setStep($step);
}, $worker, Installer::class);
$interleave = function () use ($writer, $publish, $scenario): void {
    $previous = $GLOBALS['local_db_cnn_id'];
    try {
        $GLOBALS['local_db_cnn_id'] = $writer;
        if (str_starts_with($scenario, 'complete-')) {
            $writer->prepare('UPDATE version SET cacti = ?')->execute([CACTI_VERSION]);
            set_install_config_option('install_version', CACTI_VERSION);
            set_install_config_option('install_error', '');
            set_install_config_option('install_progress', '100');
            $publish(Installer::STEP_COMPLETE);
        } else {
            set_install_config_option('install_error', 'owned worker refusal');
            $publish(Installer::STEP_ERROR);
        }
        set_install_config_option('install_complete', '100.000001');
    } finally {
        $GLOBALS['local_db_cnn_id'] = $previous;
    }
};
if (!str_ends_with($scenario, '-read') && !str_ends_with($scenario, '-write')) $interleave = null;
$before = $reader->query('SELECT name,value FROM settings ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
if (str_starts_with($scenario, 'normalize-')) {
    $input = match ($scenario) {
        'normalize-invalid' => 'invalid',
        'normalize-zero' => '0',
        'normalize-negative' => -1,
        'normalize-outside' => '100',
    };
    $hydrate = Closure::bind(function (mixed $step): void {
        $this->setStep($step, false);
    }, $worker, Installer::class);
    $hydrate($input);
    $poll = $worker;
} else {
    $poll = new Installer(['Runtime' => 'Json', 'Step' => Installer::STEP_INSTALL]);
}
$observed = $reader->query('SELECT name,value FROM settings ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
$pollAgain = str_starts_with($scenario, 'normalize-') ? $poll : new Installer(['Runtime' => 'Json', 'Step' => Installer::STEP_INSTALL]);
$afterAgain = $reader->query('SELECT name,value FROM settings ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
$readback = read_config_option('install_step', true);
$navigation = [
    'Prev' => (new ReflectionProperty(Installer::class, 'buttonPrevious'))->getValue($pollAgain),
    'Next' => (new ReflectionProperty(Installer::class, 'buttonNext'))->getValue($pollAgain),
];
// Serialize the actual UI button protocol before the measured scenario ends.
$navigation = json_decode(json_encode($navigation, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
if ($coverage !== null) {
    $coverage->stop();
    $report = $directory . '/state.coverage';
    $bytes = serialize($coverage);
    if (file_put_contents($report, $bytes) !== strlen($bytes)) throw new RuntimeException('Incomplete installer coverage report.');
    NativeChildCoverageEvidence::write($report, $root, $evidence, InstallerStateEvidence::MARKERS);
}
fwrite(STDOUT, json_encode(['before' => $before, 'navigation' => $navigation, 'readback' => $readback, 'next_local_step' => $pollAgain->getStep(), 'next_persisted' => $afterAgain, 'local_step' => $poll->getStep(), 'persisted' => $observed, 'writes' => $writes], JSON_THROW_ON_ERROR));
