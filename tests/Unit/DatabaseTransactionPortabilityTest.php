<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

$databaseSource = file_get_contents(dirname(__DIR__, 2) . '/lib/database.php');

test('the commit path does not depend on the MariaDB in_transaction variable', function () use ($databaseSource) {
    $start = strpos($databaseSource, 'function db_commit_transaction(');
    $end   = strpos($databaseSource, "\nfunction ", $start + 1);
    $body  = substr($databaseSource, $start, $end - $start);

    // MySQL answers "Unknown system variable 'in_transaction'", so the guard was
    // always false there and the commit never ran.
    expect($body)->not->toContain("SELECT @@in_transaction")
        ->and($body)->toContain('inTransaction()');
});

test('PDO reports transaction state identically regardless of engine', function () {
    $pdo = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));

    expect($pdo->inTransaction())->toBeFalse();

    $pdo->beginTransaction();
    expect($pdo->inTransaction())->toBeTrue();

    expect($pdo->commit())->toBeTrue()
        ->and($pdo->inTransaction())->toBeFalse();
});

test('the real function commits an open transaction and refuses a closed one', function () use ($databaseSource) {
    // The function is evaluated on its own: bootstrap-unit.php skips include/global.php.
    require_once dirname(__DIR__, 2) . '/tests/Helpers/PhpSource.php';
    eval(test_php_function_source($databaseSource, 'db_commit_transaction')); // nosemgrep: php.lang.security.eval-use.eval-use

    $pdo = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    $pdo->exec('CREATE TABLE probe (id INTEGER)');

    // Nothing open: refused rather than returning null, and nothing throws.
    expect(db_commit_transaction($pdo))->toBeFalse();

    $pdo->beginTransaction();
    $pdo->exec('INSERT INTO probe (id) VALUES (1)');

    expect(db_commit_transaction($pdo))->toBeTrue()
        ->and($pdo->inTransaction())->toBeFalse()
        ->and((int) $pdo->query('SELECT COUNT(*) FROM probe')->fetchColumn())->toBe(1);
});
