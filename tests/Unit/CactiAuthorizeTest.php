<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace CactiAuthorizeBehavior;

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';
$source = file_get_contents(dirname(__DIR__, 2) . '/lib/auth.php');
if (!is_string($source)) {
    throw new \RuntimeException('Unable to read authorization helpers.');
}
foreach (['cacti_authorize_resource', 'cacti_authorize_is_admin', 'cacti_authorize_has_realm'] as $name) {
    eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source($source, $name)); // nosemgrep: php.lang.security.eval-use.eval-use
}

final class AuthorizationFixture
{
    public static ?\PDO $database = null;
    public static int $queries = 0;
}

function db_fetch_cell_prepared(string $sql, array $parameters = []): mixed
{
    AuthorizationFixture::$queries++;
    $statement = AuthorizationFixture::$database->prepare($sql);
    $statement->execute($parameters);

    return $statement->fetchColumn();
}

function db_table_exists(string $table): bool
{
    $statement = AuthorizationFixture::$database->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
    $statement->execute([$table]);

    return $statement->fetchColumn() !== false;
}

beforeAll(function () {
    AuthorizationFixture::$database = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    AuthorizationFixture::$database->exec("CREATE TABLE reports(id INTEGER PRIMARY KEY,user_id INTEGER);
        INSERT INTO reports VALUES(42,5),(43,0);
        CREATE TABLE reports_items(id INTEGER PRIMARY KEY,report_id INTEGER);
        INSERT INTO reports_items VALUES(80,42),(81,999);
        CREATE TABLE graph_tree(id INTEGER PRIMARY KEY,user_id INTEGER);
        INSERT INTO graph_tree VALUES(42,5),(43,0);
        CREATE TABLE user_auth_realm(user_id INTEGER,realm_id INTEGER);
        INSERT INTO user_auth_realm VALUES(99,1),(12,21);
        CREATE TABLE user_auth_group(id INTEGER PRIMARY KEY,enabled TEXT);
        INSERT INTO user_auth_group VALUES(1,'on'),(2,'');
        CREATE TABLE user_auth_group_members(user_id INTEGER,group_id INTEGER);
        INSERT INTO user_auth_group_members VALUES(13,1),(14,2);
        CREATE TABLE user_auth_group_realm(group_id INTEGER,realm_id INTEGER);
        INSERT INTO user_auth_group_realm VALUES(1,21),(2,21);");
});

afterAll(function () {
    AuthorizationFixture::$database = null;
});

test('authorization uses production ownership and direct or enabled-group report realms', function (int $user, int $resource, string $type, bool $allowed) {
    expect(cacti_authorize_resource($user, $resource, $type))->toBe($allowed);
})->with([
    'report owner' => [5, 42, 'reports', true],
    'foreign report' => [7, 42, 'reports', false],
    'missing report' => [5, 999, 'reports', false],
    'orphan report' => [5, 43, 'reports', false],
    'report administrator' => [12, 42, 'reports', true],
    'enabled group report administrator' => [13, 42, 'reports', true],
    'disabled group report administrator' => [14, 42, 'reports', false],
    'report item owner' => [5, 80, 'report_item', true],
    'foreign report item' => [7, 80, 'report_item', false],
    'missing parent' => [5, 81, 'report_item', false],
    'report item administrator' => [12, 80, 'report_item', true],
    'enabled group item administrator' => [13, 80, 'report_item', true],
    'disabled group item administrator' => [14, 80, 'report_item', false],
    'tree owner' => [5, 42, 'graph_tree', true],
    'foreign tree' => [7, 42, 'graph_tree', false],
    'orphan tree' => [5, 43, 'graph_tree', false],
    'report realm does not grant tree ownership' => [12, 42, 'graph_tree', false],
    'own settings' => [5, 5, 'settings_user', true],
    'foreign settings' => [5, 7, 'settings_user', false],
    'unknown resource type' => [5, 42, 'unknown', false],
    'system administrator' => [99, 42, 'reports', true],
    'system administrator tree' => [99, 42, 'graph_tree', true],
]);

test('invalid identities are refused before any protected query', function (int $user, int $resource) {
    $before = AuthorizationFixture::$queries;
    expect(cacti_authorize_resource($user, $resource, 'reports'))->toBeFalse()
        ->and(AuthorizationFixture::$queries)->toBe($before);
})->with([[0, 42], [-1, 42], [5, 0], [5, -1], [99, 0]]);

test('production administrator lookups are cached within the request', function () {
    cacti_authorize_is_admin(99);
    $before = AuthorizationFixture::$queries;
    expect(cacti_authorize_is_admin(99))->toBeTrue()->and(AuthorizationFixture::$queries)->toBe($before);
});
