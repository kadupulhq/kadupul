<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

$root = dirname(__DIR__, 2);
define('CACTI_VERSION', trim(file_get_contents($root . '/include/cacti_version')));
require $root . '/lib/installer.php';
require $root . '/tests/Helpers/PhpSource.php';
eval(test_php_function_source(file_get_contents($root . '/tests/security/cdef_reference_installer_native_probe.php'), 'installerSeed'));
function versionAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "PASS $message\n";
}
$dsn = getenv('KADUPUL_REFERENCE_TEST_DSN');
if (!is_string($dsn) || !str_starts_with($dsn, 'mysql:')) {
    throw new RuntimeException('An explicit native version fixture is required.');
}
$database = new PDO(
    $dsn,
    getenv('KADUPUL_REFERENCE_TEST_USER') ?: '',
    getenv('KADUPUL_REFERENCE_TEST_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT, PDO::ATTR_EMULATE_PREPARES => false]
);
$database_hostname = 'version-fixture';
$database_port = 0;
$database_default = 'version-fixture';
$database_sessions = ['version-fixture:0:version-fixture' => $database];
$schema = 'kadupul_cdef_version_' . bin2hex(random_bytes(8));
$created = false;
$installer = (new ReflectionClass(Installer::class))->newInstanceWithoutConstructor();
$method = new ReflectionMethod(Installer::class, 'recordInstalledVersion');
$confirm = static fn(): bool => $method->invoke($installer);
$versions = static fn(): array => $database->query('SELECT cacti FROM version ORDER BY cacti')->fetchAll(PDO::FETCH_COLUMN);
try {
    echo 'SERVER ' . $database->query('SELECT VERSION()')->fetchColumn() . "\n";
    $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $database->exec("CREATE DATABASE `$schema`");
    $created = true;
    $database->exec("USE `$schema`");
    installerSeed($database, $root);
    $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
    foreach (['new_install', '1.2.33', null, CACTI_VERSION] as $previous) {
        $database->exec('DELETE FROM version');
        if ($previous !== null) {
            $database->prepare('INSERT INTO version VALUES(?)')->execute([$previous]);
        }
        versionAssert(
            $confirm() && $versions() === [CACTI_VERSION] && !$database->inTransaction(),
            'actual fresh/upgrade/empty/idempotent version marker is confirmed'
        );
    }
    foreach (['INSERT' => null, 'UPDATE' => '1.2.33'] as $operation => $previous) {
        $database->exec('DELETE FROM version');
        if ($previous !== null) {
            $database->prepare('INSERT INTO version VALUES(?)')->execute([$previous]);
        }
        $before = $versions();
        $database->exec("CREATE TRIGGER proof_version_refusal BEFORE $operation ON version FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='version write refused'");
        $statement = $database->prepare($operation === 'INSERT' ? 'INSERT INTO version VALUES(?)' : 'UPDATE version SET cacti=?');
        versionAssert(
            !$statement->execute([CACTI_VERSION]) && $statement->errorCode() === '45000',
            "actual native silent $operation refusal is reached"
        );
        versionAssert(
            !$confirm() && $versions() === $before && !$database->inTransaction(),
            "actual native $operation refusal preserves retry marker"
        );
        $database->exec('DROP TRIGGER proof_version_refusal');
        versionAssert($confirm() && $versions() === [CACTI_VERSION], "actual native $operation refusal can be retried");
        $database->exec('DELETE FROM version');
        if ($previous !== null) {
            $database->prepare('INSERT INTO version VALUES(?)')->execute([$previous]);
        }
        $database->exec("CREATE TRIGGER proof_version_coercion BEFORE $operation ON version FOR EACH ROW SET NEW.cacti='wrong-marker'");
        $database->beginTransaction();
        $statement = $database->prepare($operation === 'INSERT' ? 'INSERT INTO version VALUES(?)' : 'UPDATE version SET cacti=?');
        versionAssert(
            $statement->execute([CACTI_VERSION]) && $versions() === ['wrong-marker'],
            "actual native successful $operation readback mismatch is reached"
        );
        $database->rollBack();
        versionAssert(
            !$confirm() && $versions() === $before && !$database->inTransaction(),
            "actual native $operation mismatch rolls back before publishing current version"
        );
        $database->exec('DROP TRIGGER proof_version_coercion');
    }
    $database->exec('DELETE FROM version');
    $database->exec("INSERT INTO version VALUES('1.2.33')");
    $database->beginTransaction();
    $database->exec("INSERT INTO settings (name,value) VALUES('proof_version_caller_work','preserve')");
    versionAssert(
        !$confirm() && $database->inTransaction() && $versions() === ['1.2.33']
        && $database->query("SELECT value FROM settings WHERE name='proof_version_caller_work'")->fetchColumn() === 'preserve',
        'actual native caller transaction and earlier writes remain owned by caller'
    );
    $database->rollBack();
    $database->exec("INSERT INTO version VALUES('1.2.32')");
    versionAssert(!$confirm() && $versions() === ['1.2.32', '1.2.33'], 'actual malformed multiple markers are preserved on refusal');
    $database->exec('DELETE FROM version WHERE cacti=\'1.2.32\'');
    $create = $database->query('SHOW CREATE TABLE version')->fetch(PDO::FETCH_ASSOC)['Create Table'];
    $database->exec(str_replace('CREATE TABLE ', 'CREATE TEMPORARY TABLE ', $create));
    versionAssert(!$confirm() && $versions() === [], 'actual temporary version shadow is refused before insertion');
    $database->exec('DROP TEMPORARY TABLE version');
    $database->exec('ALTER TABLE version ENGINE=MyISAM');
    versionAssert(!$confirm() && $versions() === ['1.2.33'], 'actual nontransactional version marker is refused before mutation');
} finally {
    $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if ($database->inTransaction()) {
        $database->rollBack();
    }
    if ($created) {
        $database->exec("DROP DATABASE `$schema`");
    }
}
