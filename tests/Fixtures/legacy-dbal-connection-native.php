<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Runs the real lib/database.php connection and helpers next to the DBAL
// bridge on one server session and prints what each side observed as JSON.

use Doctrine\DBAL\DriverManager;
use Kadupul\Platform\Infrastructure\Legacy\Dbal\LegacyDatabaseCall;
use Kadupul\Platform\Infrastructure\Legacy\Dbal\LegacyDatabaseConnection;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 2);
$scenario = $argv[1] ?? 'contract';
require $root . '/include/vendor/autoload.php';
require $root . '/include/global_constants.php';
require $root . '/lib/database.php';
require $root . '/tests/Helpers/PhpSource.php';
// The repository's own helpers, extracted as the other native fixtures do,
// so the helpers under test call the real implementations.
foreach (['cacti_sizeof', 'cacti_count', 'clean_up_lines'] as $function) {
    eval(test_php_function_source(file_get_contents($root . '/lib/functions.php'), $function));
}

$logged = [];

// Same signature as lib/functions.php; records instead of writing the log.
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
$user = getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root';
$password = getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '';

// A config.php that would leave a mark if anything included it.
$base = sys_get_temp_dir() . '/kadupul-dbal-bridge-' . bin2hex(random_bytes(8));
mkdir($base . '/include', 0700, true);
$marker = $base . '/config-was-read';
file_put_contents($base . '/include/config.php', '<?php touch(' . var_export($marker, true) . ');');
$config = ['poller_id' => 1, 'base_path' => $base];
$database_hostname = (string) $dsn['host'];
$database_port = (string) ($dsn['port'] ?? '3306');
$database_default = (string) $dsn['dbname'];
$legacy = db_connect_real($database_hostname, $user, $password, $database_default, 'mysql', $database_port, 2);
if (!$legacy instanceof PDO) {
    fwrite(STDERR, 'legacy connection failed: ' . json_encode($config['DATABASE_ERROR'] ?? null));
    exit(1);
}

// The utf8mb4 session that the Doctrine ConnectionTarget connections open.
$observer = DriverManager::getConnection(['driver' => 'pdo_mysql', 'host' => $database_hostname, 'port' => (int) $database_port, 'dbname' => $database_default, 'user' => $user, 'password' => $password, 'charset' => 'utf8mb4']);
$table = 'dbalbr_items_' . bin2hex(random_bytes(6));
$observer->executeStatement("CREATE TABLE `$table` (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$observer->executeStatement("INSERT INTO `$table` (name) VALUES ('seed-1'), ('seed-2')");
$password = null;
// After connecting: db_connect_real() probes poller.timezone, which this
// throwaway schema does not have, and would throw under the flag.
if ($scenario === 'strict') {
    define('KADUPUL_THROW_DATABASE_ERRORS', true);
}

$bridge = static fn(): Doctrine\DBAL\Connection => LegacyDatabaseConnection::open(LegacyDatabaseConnection::select());
$count = static fn(Doctrine\DBAL\Connection $connection, string $name): int => (int) $connection->fetchOne("SELECT COUNT(*) FROM `$table` WHERE name = ?", [$name]);
// Sessions of this user on this schema. A health-check ping connects
// without a schema, so it cannot make the comparison flaky.
$sessions = static fn(): array => array_map(intval(...), $observer->fetchFirstColumn('SELECT ID FROM information_schema.PROCESSLIST WHERE USER = ? AND DB = ? ORDER BY ID', [$user, $database_default]));
$failure = static fn(Throwable $exception): string => $exception::class;
$out = ['scenario' => $scenario];

try {
    if ($scenario === 'strict') {
        $call = new LegacyDatabaseCall();
        foreach (['legacy' => static fn() => db_fetch_cell_prepared('SELECT name FROM dbalbr_missing WHERE id = ?', [1]), 'bridge' => static fn() => $call->cell(static fn($c) => $c->fetchOne('SELECT name FROM dbalbr_missing WHERE id = ?', [1]))] as $side => $run) {
            $logged = [];
            try {
                $run();
                $out[$side] = ['thrown' => null];
            } catch (Error $error) {
                $out[$side] = ['thrown' => $error::class, 'message' => $error->getMessage(), 'previous' => $error->getPrevious() === null];
            }
            $out[$side]['logs'] = count(array_filter($logged, static fn(array $line): bool => $line[0] !== 'backtrace'));
            $out[$side]['errmode'] = $legacy->getAttribute(PDO::ATTR_ERRMODE);
        }
    } else {
        $out['sessions_before'] = $sessions();
        $held = $bridge();
        $held->fetchOne('SELECT 1');
        $out['sessions_held'] = $sessions();
        $out['native_is_legacy'] = $held->getNativeConnection() === $legacy;

        // Same session: identical CONNECTION_ID() through both paths.
        $out['connection_id'] = ['legacy' => (int) db_fetch_cell('SELECT CONNECTION_ID()'), 'bridge' => (int) $bridge()->fetchOne('SELECT CONNECTION_ID()'), 'observer' => (int) $observer->fetchOne('SELECT CONNECTION_ID()')];

        // Legacy transaction, legacy write, DBAL read, legacy rollback.
        db_begin_transaction();
        db_execute_prepared("INSERT INTO `$table` (name) VALUES (?)", ['legacy-a']);
        $out['legacy_write'] = ['bridge_before_commit' => $count($bridge(), 'legacy-a'), 'observer_before_commit' => $count($observer, 'legacy-a')];
        $out['legacy_write']['rollback'] = db_rollback_transaction();
        $out['legacy_write']['bridge_after_rollback'] = $count($bridge(), 'legacy-a');
        $out['legacy_write']['observer_after_rollback'] = $count($observer, 'legacy-a');

        // Legacy transaction, DBAL write, legacy rollback, then legacy commit.
        db_begin_transaction();
        $bridge()->executeStatement("INSERT INTO `$table` (name) VALUES (?)", ['dbal-b']);
        $out['dbal_write'] = ['legacy_before_rollback' => (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM `$table` WHERE name = ?", ['dbal-b']), 'observer_before_rollback' => $count($observer, 'dbal-b')];
        $out['dbal_write']['rollback'] = db_rollback_transaction();
        $out['dbal_write']['legacy_after_rollback'] = (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM `$table` WHERE name = ?", ['dbal-b']);
        db_begin_transaction();
        $bridge()->executeStatement("INSERT INTO `$table` (name) VALUES (?)", ['dbal-c']);
        $out['dbal_write']['observer_before_commit'] = $count($observer, 'dbal-c');
        $out['dbal_write']['commit'] = db_commit_transaction();
        $out['dbal_write']['observer_after_commit'] = $count($observer, 'dbal-c');

        // The reverse: DBAL opens and ends the transaction around a legacy write.
        foreach (['rollback' => 'legacy-d', 'commit' => 'legacy-e'] as $end => $name) {
            $connection = $bridge();
            $connection->beginTransaction();
            $row = ['legacy_sees_transaction' => $legacy->inTransaction(), 'write' => db_execute_prepared("INSERT INTO `$table` (name) VALUES (?)", [$name])];
            $row['bridge_before_end'] = $count($connection, $name);
            $row['observer_before_end'] = $count($observer, $name);
            $end === 'commit' ? $connection->commit() : $connection->rollBack();
            $row['legacy_transaction_after_end'] = $legacy->inTransaction();
            $row['legacy_after_end'] = (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM `$table` WHERE name = ?", [$name]);
            $row['observer_after_end'] = $count($observer, $name);
            $out['reverse'][$end] = $row;
        }

        // DBAL cannot open a second transaction inside the legacy one; the
        // legacy transaction and its write survive the refusal.
        db_begin_transaction();
        db_execute_prepared("INSERT INTO `$table` (name) VALUES (?)", ['legacy-f']);
        try {
            $bridge()->beginTransaction();
            $out['nested'] = ['refused' => null];
        } catch (Throwable $exception) {
            $out['nested'] = ['refused' => $failure($exception)];
        }
        $out['nested']['legacy_transaction'] = $legacy->inTransaction();
        $out['nested']['legacy_sees_write'] = (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM `$table` WHERE name = ?", ['legacy-f']);
        db_rollback_transaction();

        // Error mode: SILENT before, after success, after each failure, and
        // a later legacy failure still returns false rather than throwing.
        $modes = ['before' => $legacy->getAttribute(PDO::ATTR_ERRMODE)];
        $bridge()->fetchAllAssociative("SELECT * FROM `$table`");
        $modes['after_success'] = $legacy->getAttribute(PDO::ATTR_ERRMODE);
        foreach (['query' => static fn($c) => $c->fetchOne('SELECT name FROM dbalbr_missing'), 'statement' => static fn($c) => $c->executeStatement('UPDATE dbalbr_missing SET name = ?', ['x']), 'exec' => static fn($c) => $c->executeStatement('DROP TABLE dbalbr_missing'), 'commit' => static fn($c) => $c->commit()] as $kind => $operation) {
            try {
                $operation($bridge());
                $modes['failure_' . $kind] = 'no exception';
            } catch (Throwable $exception) {
                $modes['failure_' . $kind] = $failure($exception);
            }
            $modes['after_failure_' . $kind] = $legacy->getAttribute(PDO::ATTR_ERRMODE);
        }
        $logged = [];
        $modes['legacy_failure_returns'] = var_export(db_fetch_cell_prepared('SELECT name FROM dbalbr_missing WHERE id = ?', [1]), true);
        $modes['legacy_failure_logged'] = count($logged) > 0;
        $modes['after_legacy_failure'] = $legacy->getAttribute(PDO::ATTR_ERRMODE);
        // A caller that chose another mode gets that mode back.
        $legacy->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
        try {
            @$bridge()->fetchOne('SELECT name FROM dbalbr_missing');
        } catch (Throwable) {
        }
        $modes['warning_restored'] = $legacy->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_WARNING;
        $legacy->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        // Between DBAL calls, inside one operation, legacy helpers see SILENT.
        $inside = [];
        (new LegacyDatabaseCall())->cell(static function ($c) use ($legacy, &$inside) {
            $value = $c->fetchOne('SELECT 1');
            $inside['mode'] = $legacy->getAttribute(PDO::ATTR_ERRMODE);
            $inside['legacy_failure'] = var_export(db_fetch_cell('SELECT * FROM dbalbr_missing', '', false), true);

            return $value;
        });
        $modes['inside_operation'] = $inside;
        $out['errmode'] = $modes;

        // Failure contracts of the helpers, run for real, next to the bridge.
        $missing = 'SELECT name FROM dbalbr_missing WHERE id = ?';
        $call = new LegacyDatabaseCall();
        $shapes = [
            'cell' => [static fn(bool $log) => db_fetch_cell_prepared($missing, [1], '', $log), static fn(bool $log) => $call->cell(static fn($c) => $c->fetchOne($missing, [1]), $log)],
            'row' => [static fn(bool $log) => db_fetch_row_prepared($missing, [1], $log), static fn(bool $log) => $call->row(static fn($c) => $c->fetchAssociative($missing, [1]), $log)],
            'assoc' => [static fn(bool $log) => db_fetch_assoc_prepared($missing, [1], $log), static fn(bool $log) => $call->assoc(static fn($c) => $c->fetchAllAssociative($missing, [1]), $log)],
            'execute' => [static fn(bool $log) => db_execute_prepared('UPDATE dbalbr_missing SET name = ? WHERE id = ?', ['x', 1], $log), static fn(bool $log) => $call->execute(static fn($c) => $c->executeStatement('UPDATE dbalbr_missing SET name = ? WHERE id = ?', ['x', 1]), $log)],
        ];
        $observe = static function (Closure $run, bool $log) use (&$logged, $legacy): array {
            global $database_last_error;
            $logged = [];
            $database_last_error = '';
            $value = $run($log);

            return ['returns' => var_export($value, true), 'db_error' => $database_last_error, 'logs' => $logged, 'errmode' => $legacy->getAttribute(PDO::ATTR_ERRMODE)];
        };
        foreach ($shapes as $shape => [$helper, $wrapper]) {
            foreach (['log' => true, 'quiet' => false] as $mode => $log) {
                $out['failure'][$shape][$mode] = ['legacy' => $observe($helper, $log), 'bridge' => $observe($wrapper, $log)];
            }
            db_begin_transaction();
            $out['failure'][$shape]['transaction'] = ['legacy' => $observe($helper, true), 'bridge' => $observe($wrapper, true)];
            db_rollback_transaction();
        }

        // Success and empty results through both paths, compared by value and type.
        $first = "SELECT id, name FROM `$table` WHERE name = ? ORDER BY id";
        foreach (['found' => 'seed-1', 'empty' => 'nobody'] as $case => $name) {
            $out['success'][$case] = [
                'cell' => [var_export(db_fetch_cell_prepared($first, [$name]), true), var_export($call->cell(static fn($c) => $c->fetchOne($first, [$name])), true)],
                'row' => [var_export(db_fetch_row_prepared($first, [$name]), true), var_export($call->row(static fn($c) => $c->fetchAssociative($first, [$name])), true)],
                'assoc' => [var_export(db_fetch_assoc_prepared($first, [$name]), true), var_export($call->assoc(static fn($c) => $c->fetchAllAssociative($first, [$name])), true)],
            ];
        }
        $out['affected'] = ['legacy_returns' => db_execute_prepared("UPDATE `$table` SET name = CONCAT(name, '') WHERE name LIKE ?", ['seed-%']), 'legacy' => db_affected_rows()];
        $out['affected']['bridge_returns'] = $call->execute(static fn($c) => $c->executeStatement("UPDATE `$table` SET name = CONCAT(name, '') WHERE name LIKE ?", ['seed-%']));
        $out['affected']['bridge'] = db_affected_rows();

        // No legacy session: both sides fail without opening one.
        $key = "$database_hostname:$database_port:$database_default";
        $saved = $database_sessions[$key];
        $database_sessions[$key] = null;
        $out['no_connection'] = ['select' => LegacyDatabaseConnection::select() === null, 'legacy' => $observe(static fn(bool $log) => db_fetch_cell('SELECT 1', '', $log), true), 'bridge' => $observe(static fn(bool $log) => $call->cell(static fn($c) => $c->fetchOne('SELECT 1'), $log), true)];
        $database_sessions[$key] = $saved;

        // Charset: utf8 (3-byte) legacy session against the utf8mb4 one.
        $observer->executeStatement("INSERT INTO `$table` (name) VALUES (?), (?), (?)", ["\u{e9}", "\u{4e2d}", "\u{1f600}"]);
        foreach (['two_byte' => "\u{e9}", 'three_byte' => "\u{4e2d}", 'four_byte' => "\u{1f600}"] as $case => $value) {
            $id = (int) $observer->fetchOne("SELECT id FROM `$table` WHERE name = ?", [$value]);
            $out['charset']['read'][$case] = [
                'stored' => bin2hex($value),
                'observer' => bin2hex((string) $observer->fetchOne("SELECT name FROM `$table` WHERE id = ?", [$id])),
                'bridge' => bin2hex((string) $bridge()->fetchOne("SELECT name FROM `$table` WHERE id = ?", [$id])),
                'legacy' => bin2hex((string) db_fetch_cell_prepared("SELECT name FROM `$table` WHERE id = ?", [$id])),
            ];
        }
        $logged = [];
        $out['charset']['write'] = ['bridge_returns' => $call->execute(static fn($c) => $c->executeStatement("INSERT INTO `$table` (name) VALUES (?)", ["bridge-\u{1f600}"]))];
        $out['charset']['write']['observer'] = bin2hex((string) $observer->fetchOne("SELECT name FROM `$table` WHERE name LIKE 'bridge-%'"));
        $out['charset']['write']['logs'] = count($logged);
        $out['charset']['session'] = ['bridge' => $bridge()->fetchAssociative('SELECT @@character_set_client AS client, @@character_set_connection AS connection, @@character_set_results AS results'), 'observer' => $observer->fetchAssociative('SELECT @@character_set_client AS client, @@character_set_connection AS connection, @@character_set_results AS results')];

        // Nothing above opened a session or read include/config.php.
        $out['sessions_after'] = $sessions();
        $out['legacy_session'] = (int) db_fetch_cell('SELECT CONNECTION_ID()');
        $out['config_read'] = is_file($marker) || in_array($base . '/include/config.php', get_included_files(), true);
        $out['final_errmode'] = $legacy->getAttribute(PDO::ATTR_ERRMODE);
        $out['server'] = (string) $observer->fetchOne('SELECT VERSION()');
    }
} finally {
    $observer->executeStatement("DROP TABLE IF EXISTS `$table`");
    @unlink($marker);
    unlink($base . '/include/config.php');
    rmdir($base . '/include');
    rmdir($base);
}

print json_encode($out, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
