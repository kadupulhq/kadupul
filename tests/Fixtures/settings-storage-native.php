<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Runs the settings statements through the real lib/database.php helpers and
// through LegacySettingsStorage on one legacy session, in a database created
// for this run and dropped afterwards, and prints what each side did as JSON.

use Kadupul\Platform\Infrastructure\Legacy\LegacyLockRetry;
use Kadupul\Platform\Infrastructure\Legacy\LegacySettingsStorage;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 2);
$scenario = $argv[1] ?? 'contract';
// The bootstrap scenario stands where include/global.php first reads a
// setting: Composer's autoloader is not loaded yet.
if ($scenario !== 'bootstrap') {
    require $root . '/include/vendor/autoload.php';
}
require $root . '/include/global_constants.php';
require $root . '/lib/database.php';
require $root . '/tests/Helpers/PhpSource.php';
// Load the bridge in the same order as lib/functions.php before Composer.
require_once $root . '/src/Platform/Infrastructure/Legacy/LegacyLockRetry.php';
require_once $root . '/src/Platform/Infrastructure/Legacy/LegacySettingsStorage.php';
foreach (['cacti_sizeof', 'cacti_count', 'clean_up_lines'] as $function) {
    eval(test_php_function_source(file_get_contents($root . '/lib/functions.php'), $function));
}

$logged = [];

// Same signatures as lib/functions.php; they record instead of writing the log.
function cacti_log($string, $output = false, $environ = 'CMDPHP', $level = '')
{
    $GLOBALS['logged'][] = [$string, $output, $environ, $level];

    return true;
}

function cacti_debug_backtrace($entry = '', $html = false, $record = true, $limit = 0, $skip = 0)
{
    $GLOBALS['logged'][] = ['backtrace', $entry, $html, $record, $limit, $skip];
}

parse_str(str_replace(';', '&', substr((string) getenv('KADUPUL_TEST_MYSQL_DSN'), strlen('mysql:'))), $dsn);
$prefix = getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') ? 'KADUPUL_TEST_MYSQL_ADMIN_' : 'KADUPUL_TEST_MYSQL_';
$user = getenv($prefix . 'USER') ?: 'root';
$password = getenv($prefix . 'PASSWORD') ?: '';
$database_hostname = (string) $dsn['host'];
$database_port = (string) ($dsn['port'] ?? '3306');
$database_default = 'kset_' . bin2hex(random_bytes(6));
$server = 'mysql:host=' . $database_hostname . ';port=' . $database_port . ';charset=utf8mb4';
$admin = new PDO($server, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$admin->exec("CREATE DATABASE `$database_default` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$out = ['scenario' => $scenario];

try {
    // The definitions from cacti.sql.
    $admin->exec("CREATE TABLE `$database_default`.settings (name varchar(255) NOT NULL default '', value varchar(4096) NOT NULL default '', PRIMARY KEY (name)) ENGINE=InnoDB ROW_FORMAT=Dynamic");
    $admin->exec("CREATE TABLE `$database_default`.settings_user (user_id mediumint(8) unsigned NOT NULL default '0', name varchar(255) NOT NULL default '', value varchar(4096) NOT NULL default '', PRIMARY KEY (user_id, name)) ENGINE=InnoDB ROW_FORMAT=Dynamic");

    $base = sys_get_temp_dir() . '/kadupul-settings-' . bin2hex(random_bytes(8));
    mkdir($base, 0700, true);
    $config = ['poller_id' => 1, 'base_path' => $base];
    $legacy = db_connect_real($database_hostname, $user, $password, $database_default, 'mysql', $database_port, 2);
    rmdir($base);
    if (!$legacy instanceof PDO) {
        throw new RuntimeException('legacy connection failed: ' . json_encode($config['DATABASE_ERROR'] ?? null));
    }
    $observer = new PDO($server . ';dbname=' . $database_default, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $password = null;

    $seed = static function () use ($observer): void {
        $observer->exec('DELETE FROM settings');
        $observer->exec('DELETE FROM settings_user');
        $observer->exec("INSERT INTO settings VALUES ('poller_interval', '300'), ('empty', ''), ('lockkey', 'seed')");
        $observer->exec("INSERT INTO settings_user VALUES (1, 'pref', 'v'), (0, 'pref', 'anon'), (2, 'pref', 'other')");
    };
    $tables = static fn(): array => [
        'settings' => array_map(static fn(array $row): array => [bin2hex($row['name']), bin2hex($row['value'])], $observer->query('SELECT name, value FROM settings ORDER BY name')->fetchAll(PDO::FETCH_ASSOC)),
        'settings_user' => array_map(static fn(array $row): array => [(int) $row['user_id'], bin2hex($row['name']), bin2hex($row['value'])], $observer->query('SELECT user_id, name, value FROM settings_user ORDER BY user_id, name')->fetchAll(PDO::FETCH_ASSOC)),
    ];
    $observe = static function (Closure $run) use (&$logged): array {
        global $database_last_error, $affected_rows, $database_total_queries;
        $logged = [];
        $database_last_error = '';
        $affected_rows = [];
        $queries = $database_total_queries;
        try {
            $value = $run();
            $thrown = null;
        } catch (Throwable $error) {
            $value = null;
            $thrown = [$error::class, $error->getMessage()];
        }

        return ['returns' => var_export($value, true), 'thrown' => $thrown, 'db_error' => $database_last_error, 'logs' => $logged, 'affected' => db_affected_rows(), 'counted' => $database_total_queries - $queries];
    };
    // Built here, not above, so the bootstrap scenario loads it itself.
    $storage = $scenario === 'bootstrap' ? null : new LegacySettingsStorage();

    if ($scenario === 'bootstrap') {
        $seed();
        $out['bootstrap'] = [
            'composer_loaded_before' => class_exists('Composer\Autoload\ClassLoader', false),
            'write' => LegacySettingsStorage::instance()->writeSetting('boot', 'x'),
            'read' => LegacySettingsStorage::instance()->settingRow('boot'),
            'dbal_loaded' => class_exists('Doctrine\DBAL\Connection', false),
            'storage_classes' => array_values(array_filter(get_declared_classes(), static fn(string $class): bool => str_starts_with($class, 'Kadupul\\'))),
            'composer_init' => array_values(array_filter(get_declared_classes(), static fn(string $class): bool => str_starts_with($class, 'ComposerAutoloaderInit'))),
            // Composer's file autoloads define namespaced or guarded global
            // functions; none of them may run this early.
            'vendor_functions' => array_values(array_filter(get_defined_functions()['user'], static fn(string $name): bool => str_contains($name, '\\') || in_array($name, ['dump', 'dd'], true))),
        ];
    } elseif ($scenario === 'strict') {
        define('KADUPUL_THROW_DATABASE_ERRORS', true);
        $seed();
        $admin->exec("RENAME TABLE `$database_default`.settings TO `$database_default`.settings_away");
        foreach (['read' => [static fn() => db_fetch_row_prepared('SELECT value FROM settings WHERE name = ?', ['poller_interval']), static fn() => $storage->settingRow('poller_interval')], 'write' => [static fn() => db_execute_prepared('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)', ['n', 'v']), static fn() => $storage->writeSetting('n', 'v')]] as $case => [$helper, $port]) {
            $out['strict'][$case] = ['helper' => $observe($helper), 'storage' => $observe($port)];
        }
        $admin->exec("RENAME TABLE `$database_default`.settings_away TO `$database_default`.settings");
    } else {
        $settingRow = static fn(mixed $name, bool $log): Closure => static fn() => db_fetch_row_prepared('SELECT value FROM settings WHERE name = ?', [$name], $log);
        $settingExists = static fn(mixed $name): Closure => static fn() => db_fetch_cell_prepared('SELECT COUNT(*) FROM settings WHERE name = ?', [$name]) > 0;
        $settingValues = static fn(array $names): Closure => static function () use ($names) {
            $rows = db_fetch_assoc_prepared('SELECT name, value FROM settings WHERE name IN (' . trim(str_repeat('?, ', count($names)), ', ') . ')', $names);

            return is_array($rows) ? array_column($rows, 'value', 'name') : [];
        };
        $writeSetting = static fn(mixed $name, mixed $value): Closure => static fn() => db_execute_prepared('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)', [$name, $value]);
        $userRow = static fn(mixed $name, mixed $user): Closure => static fn() => db_fetch_row_prepared('SELECT value FROM settings_user WHERE name = ? AND user_id = ?', [$name, $user]);
        $userExists = static function (mixed $name, mixed $user): Closure {
            return static function () use ($name, $user) {
                $count = db_fetch_cell_prepared('SELECT COUNT(*) FROM settings_user WHERE name = ? AND user_id = ?', [$name, $user]);

                return $count !== false && $count > 0;
            };
        };
        $writeUser = static fn(mixed $user, mixed $name, mixed $value): Closure => static fn() => db_execute_prepared('INSERT INTO settings_user (user_id, name, value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)', [$user, $name, $value]);
        $removeUser = static fn(mixed $name, mixed $user): Closure => static fn() => db_execute_prepared('DELETE FROM settings_user WHERE name = ? AND user_id = ?', [$name, $user]);

        // Each pair: the helper call lib/functions.php made before, and the storage call it makes now.
        $pairs = [
            'row_hit' => [$settingRow('poller_interval', true), static fn() => $storage->settingRow('poller_interval')],
            'row_case_folded' => [$settingRow('POLLER_INTERVAL', true), static fn() => $storage->settingRow('POLLER_INTERVAL')],
            'row_accent_folded' => [$settingRow("poller_interv\u{e0}l", true), static fn() => $storage->settingRow("poller_interv\u{e0}l")],
            'row_empty_value' => [$settingRow('empty', true), static fn() => $storage->settingRow('empty')],
            'row_miss' => [$settingRow('missing', true), static fn() => $storage->settingRow('missing')],
            'row_miss_quiet' => [$settingRow('missing', false), static fn() => $storage->settingRow('missing', false)],
            'row_null_name' => [$settingRow(null, true), static fn() => $storage->settingRow(null)],
            'exists_hit' => [$settingExists('poller_interval'), static fn() => $storage->settingExists('poller_interval')],
            'exists_empty_value' => [$settingExists('empty'), static fn() => $storage->settingExists('empty')],
            'exists_miss' => [$settingExists('missing'), static fn() => $storage->settingExists('missing')],
            'values' => [$settingValues(['poller_interval', 'empty', 'missing', 'POLLER_INTERVAL']), static fn() => $storage->settingValues(['poller_interval', 'empty', 'missing', 'POLLER_INTERVAL'])],
            'values_none' => [$settingValues(['missing']), static fn() => $storage->settingValues(['missing'])],
            'write_insert' => [$writeSetting('new', 'x'), static fn() => $storage->writeSetting('new', 'x')],
            'write_update' => [$writeSetting('poller_interval', '60'), static fn() => $storage->writeSetting('poller_interval', '60')],
            'write_unchanged' => [$writeSetting('poller_interval', '300'), static fn() => $storage->writeSetting('poller_interval', '300')],
            'write_case_variant' => [$writeSetting('POLLER_INTERVAL', '61'), static fn() => $storage->writeSetting('POLLER_INTERVAL', '61')],
            'write_int' => [$writeSetting('new', 5), static fn() => $storage->writeSetting('new', 5)],
            'write_value_4097' => [$writeSetting('new', str_repeat('v', 4097)), static fn() => $storage->writeSetting('new', str_repeat('v', 4097))],
            'write_name_300' => [$writeSetting(str_repeat('n', 300), 'v'), static fn() => $storage->writeSetting(str_repeat('n', 300), 'v')],
            'write_invalid_utf8' => [$writeSetting('new', "\xc3\x28\xff"), static fn() => $storage->writeSetting('new', "\xc3\x28\xff")],
            'write_null_value' => [$writeSetting('new', null), static fn() => $storage->writeSetting('new', null)],
            'user_row_hit' => [$userRow('pref', 1), static fn() => $storage->userSettingRow('pref', 1)],
            'user_row_text_id' => [$userRow('pref', '1'), static fn() => $storage->userSettingRow('pref', '1')],
            'user_row_case_folded' => [$userRow('PREF', 2), static fn() => $storage->userSettingRow('PREF', 2)],
            'user_row_non_numeric_id' => [$userRow('pref', 'abc'), static fn() => $storage->userSettingRow('pref', 'abc')],
            'user_row_miss' => [$userRow('pref', 9), static fn() => $storage->userSettingRow('pref', 9)],
            'user_exists_hit' => [$userExists('pref', 1), static fn() => $storage->userSettingExists('pref', 1)],
            'user_exists_miss' => [$userExists('other', 1), static fn() => $storage->userSettingExists('other', 1)],
            'user_write_update' => [$writeUser(1, 'pref', 'w'), static fn() => $storage->writeUserSetting(1, 'pref', 'w')],
            'user_write_insert_text_id' => [$writeUser('3', 'new', 'x'), static fn() => $storage->writeUserSetting('3', 'new', 'x')],
            'user_write_non_numeric_id' => [$writeUser('abc', 'new', 'x'), static fn() => $storage->writeUserSetting('abc', 'new', 'x')],
            'user_write_unchanged' => [$writeUser(1, 'pref', 'v'), static fn() => $storage->writeUserSetting(1, 'pref', 'v')],
            'user_write_value_4097' => [$writeUser(1, 'pref', str_repeat('v', 4097)), static fn() => $storage->writeUserSetting(1, 'pref', str_repeat('v', 4097))],
            'user_write_null_user' => [$writeUser(null, 'pref', 'v'), static fn() => $storage->writeUserSetting(null, 'pref', 'v')],
            'user_remove_hit' => [$removeUser('pref', 1), static fn() => $storage->removeUserSetting('pref', 1)],
            'user_remove_non_numeric_id' => [$removeUser('pref', 'abc'), static fn() => $storage->removeUserSetting('pref', 'abc')],
            'user_remove_miss' => [$removeUser('missing', 1), static fn() => $storage->removeUserSetting('missing', 1)],
        ];
        foreach ($pairs as $case => [$helper, $port]) {
            $seed();
            $before = $observe($helper);
            $after = $tables();
            $seed();
            $out['parity'][$case] = ['helper' => $before + ['tables' => $after], 'storage' => $observe($port) + ['tables' => $tables()]];
        }

        // Failures: the tables are away. Logged outside a transaction, quiet
        // inside one and for read_config_option()'s $log = false.
        $seed();
        $admin->exec("RENAME TABLE `$database_default`.settings TO `$database_default`.settings_away, `$database_default`.settings_user TO `$database_default`.settings_user_away");
        $failing = [
            'row' => [$settingRow('poller_interval', true), static fn() => $storage->settingRow('poller_interval')],
            'row_quiet' => [$settingRow('poller_interval', false), static fn() => $storage->settingRow('poller_interval', false)],
            'exists' => [$settingExists('poller_interval'), static fn() => $storage->settingExists('poller_interval')],
            'values' => [$settingValues(['poller_interval']), static fn() => $storage->settingValues(['poller_interval'])],
            'write' => [$writeSetting('n', 'v'), static fn() => $storage->writeSetting('n', 'v')],
            'user_row' => [$userRow('pref', 1), static fn() => $storage->userSettingRow('pref', 1)],
            'user_exists' => [$userExists('pref', 1), static fn() => $storage->userSettingExists('pref', 1)],
            'user_write' => [$writeUser(1, 'pref', 'w'), static fn() => $storage->writeUserSetting(1, 'pref', 'w')],
            'user_remove' => [$removeUser('pref', 1), static fn() => $storage->removeUserSetting('pref', 1)],
        ];
        foreach ($failing as $case => [$helper, $port]) {
            $out['failure'][$case]['outside'] = ['helper' => $observe($helper), 'storage' => $observe($port)];
            db_begin_transaction();
            $out['failure'][$case]['transaction'] = ['helper' => $observe($helper), 'storage' => $observe($port)];
            db_rollback_transaction();
        }
        $admin->exec("RENAME TABLE `$database_default`.settings_away TO `$database_default`.settings, `$database_default`.settings_user_away TO `$database_default`.settings_user");

        // One session: storage writes join the legacy transaction and reads
        // see its uncommitted rows; another session sees neither.
        $seed();
        $visible = static fn(string $name): int => (int) $observer->query('SELECT COUNT(*) FROM settings WHERE name = ' . $observer->quote($name))->fetchColumn();
        db_begin_transaction();
        $share = ['write' => $storage->writeSetting('shared', 'in-transaction')];
        $share['legacy_sees_write'] = db_fetch_cell_prepared('SELECT value FROM settings WHERE name = ?', ['shared']);
        $share['observer_sees_write'] = $visible('shared');
        db_execute_prepared('INSERT INTO settings (name, value) VALUES (?, ?)', ['legacy-row', 'uncommitted']);
        $share['storage_sees_legacy_write'] = $storage->settingRow('legacy-row');
        $share['observer_sees_legacy_write'] = $visible('legacy-row');
        $share['rollback'] = db_rollback_transaction();
        $share['after_rollback'] = ['storage' => $storage->settingRow('shared'), 'observer' => $visible('shared') + $visible('legacy-row')];
        $share['session'] = ['legacy' => (int) db_fetch_cell('SELECT CONNECTION_ID()'), 'observer' => (int) $observer->query('SELECT CONNECTION_ID()')->fetchColumn()];
        $out['share'] = $share;

        // Lock waits: another session holds the row; this one gives up after
        // one second. Outside a transaction the write retries after the
        // helper's pause, by which time the holder has committed. Inside one
        // it reports the failure and leaves the transaction to its owner.
        $seed();
        db_execute('SET SESSION innodb_lock_wait_timeout = 1');
        $pauses = [];
        $holder = new PDO($server . ';dbname=' . $database_default, $user, getenv($prefix . 'PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $hold = static function () use ($holder): void {
            $holder->beginTransaction();
            $holder->query("SELECT value FROM settings WHERE name = 'lockkey' FOR UPDATE")->fetchAll();
        };
        $retrying = new LegacySettingsStorage(new LegacyLockRetry(static function (int $microseconds) use (&$pauses, $holder): void {
            $pauses[] = $microseconds;
            if ($holder->inTransaction()) {
                $holder->commit();
            }
        }));
        $hold();
        $outside = $observe(static fn() => $retrying->writeSetting('lockkey', 'outside'));
        $out['lock']['outside'] = $outside + ['pauses' => $pauses, 'stored' => $observer->query("SELECT value FROM settings WHERE name = 'lockkey'")->fetchColumn()];
        $pauses = [];
        $hold();
        db_begin_transaction();
        $inside = $observe(static fn() => $retrying->writeSetting('lockkey', 'inside'));
        $out['lock']['inside'] = $inside + ['pauses' => $pauses, 'still_in_transaction' => $legacy->inTransaction()];
        db_rollback_transaction();
        // Still held unless a retry ran the pause, which commits it.
        if ($holder->inTransaction()) {
            $holder->rollBack();
        }
        $out['lock']['inside']['stored'] = $observer->query("SELECT value FROM settings WHERE name = 'lockkey'")->fetchColumn();
        $out['server'] = (string) $observer->query('SELECT VERSION()')->fetchColumn();
    }
} finally {
    // A failed step can leave a transaction open, whose metadata lock would
    // hold the DROP forever; end every session of this run first.
    foreach ([$holder ?? null, $observer ?? null, $legacy ?? null] as $session) {
        if ($session instanceof PDO && $session->inTransaction()) {
            $session->rollBack();
        }
    }
    $holder = $observer = $legacy = null;
    $database_sessions = [];
    $admin->exec('SET SESSION lock_wait_timeout = 10');
    $admin->exec("DROP DATABASE IF EXISTS `$database_default`");
}

print json_encode($out, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
