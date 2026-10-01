<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Kadupul\IdentityAccess\Infrastructure\Legacy\LegacyAuthenticatedSession;
use Kadupul\IdentityAccess\Infrastructure\Legacy\SharedSession;
use Kadupul\Kernel;
use Kadupul\Navigation\Infrastructure\Legacy\LegacyLinkAccess;
use Kadupul\Platform\Infrastructure\Legacy\InstallationConfiguration;
use Kadupul\Platform\Infrastructure\Legacy\InstallationDatabase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require getcwd() . '/include/vendor/autoload.php';
$input = json_decode($argv[1], true, 32, JSON_THROW_ON_ERROR);
$userId = (int) $input['user'];
$_COOKIE['Cacti'] = $input['cookie'];
$kernel = new Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$container->get(RequestStack::class)->push(Request::create('/links/new', 'GET', [], $_COOKIE));
$session = $container->get(SharedSession::class);
$connections = [];
$guards = [];
$consoles = [];
for ($i = 0; $i < 3; ++$i) {
    $database = new InstallationDatabase(new InstallationConfiguration(getcwd()));
    $connections[] = $database->get();
    $database->get()->exec('SET SESSION innodb_lock_wait_timeout = 1');
    if ($i < 2) {
        $consoles[] = new LegacyAuthenticatedSession($session, $database);
        $guards[] = new LegacyLinkAccess($consoles[$i], $database);
    }
}
[$first, $second, $rival] = $connections;
$results = [];
$groupId = null;
$guestPresent = $rival->query("SELECT value FROM settings WHERE name = 'guest_user'")->fetchColumn() !== false;
$blocked = static function (string $mutation) use ($rival): bool {
    $rival->beginTransaction();
    try {
        $rival->exec($mutation);
        return false;
    } catch (PDOException $error) {
        if (($error->errorInfo[1] ?? null) !== 1205) {
            throw $error;
        }
        return true;
    } finally {
        $rival->rollBack();
    }
};
try {
    // The fresh installation omits this optional setting. Materialize the
    // policy row so its update is an actual competing revocation, not a no-op.
    if (!$guestPresent) {
        $rival->exec("INSERT INTO settings (name, value) VALUES ('guest_user', '0')");
    }
    $direct = [
        'account' => "UPDATE user_auth SET enabled = '' WHERE id = $userId",
        'console' => "DELETE FROM user_auth_realm WHERE user_id = $userId AND realm_id = 8",
        'links' => "DELETE FROM user_auth_realm WHERE user_id = $userId AND realm_id = 15",
        'auth_method' => "UPDATE settings SET value = '0' WHERE name = 'auth_method'",
        'guest' => "UPDATE settings SET value = 'admin' WHERE name = 'guest_user'",
    ];
    $rival->exec("INSERT INTO user_auth_group (name, enabled) VALUES ('link-lock-fixture', 'on')");
    $groupId = (int) $rival->lastInsertId();
    $rival->exec("INSERT INTO user_auth_group_members (group_id, user_id) VALUES ($groupId, $userId)");
    $rival->exec("INSERT INTO user_auth_group_realm (group_id, realm_id) VALUES ($groupId, 8), ($groupId, 15)");
    foreach (['direct', 'group'] as $mode) {
        if ($mode === 'group') {
            $rival->exec("DELETE FROM user_auth_realm WHERE user_id = $userId AND realm_id IN (8, 15)");
        }
        $mutations = $mode === 'direct' ? $direct : [
            'membership' => "DELETE FROM user_auth_group_members WHERE group_id = $groupId AND user_id = $userId",
            'group_enabled' => "UPDATE user_auth_group SET enabled = '' WHERE id = $groupId",
            'group_console' => "DELETE FROM user_auth_group_realm WHERE group_id = $groupId AND realm_id = 8",
            'group_links' => "DELETE FROM user_auth_group_realm WHERE group_id = $groupId AND realm_id = 15",
        ];
        foreach ($mutations as $case => $mutation) {
            $first->beginTransaction();
            $second->beginTransaction();
            // Resume the actual HTTP cookie and acquire the console adapter's
            // shared policy locks in BOTH concurrent transactions first.
            foreach ($consoles as $console) {
                if ($console->consoleActor()?->id !== $userId) {
                    throw new RuntimeException('Actual shared session authorization failed.');
                }
            }
            // The old Navigation FOR UPDATE recheck times out here attempting
            // to upgrade locks held by the other authorized request.
            foreach ($guards as $guard) {
                $guard->assertCurrent($userId);
            }
            $results[$mode . '_' . $case . '_concurrent'] = true;
            $results[$mode . '_' . $case . '_held'] = $blocked($mutation);
            $first->commit();
            $results[$mode . '_' . $case . '_second_held'] = $blocked($mutation);
            $second->commit();
            $rival->beginTransaction();
            $rival->exec($mutation);
            $results[$mode . '_' . $case . '_released'] = $rival->query('SELECT ROW_COUNT()')->fetchColumn() > 0;
            $rival->rollBack();
        }
    }
} finally {
    foreach ($connections as $connection) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
    }
    $rival->exec("REPLACE INTO user_auth_realm (user_id, realm_id) VALUES ($userId, 8), ($userId, 15)");
    if ($groupId !== null) {
        $rival->exec("DELETE FROM user_auth_group_realm WHERE group_id = $groupId");
        $rival->exec("DELETE FROM user_auth_group_members WHERE group_id = $groupId");
        $rival->exec("DELETE FROM user_auth_group WHERE id = $groupId");
    }
    if (!$guestPresent) {
        $rival->exec("DELETE FROM settings WHERE name = 'guest_user'");
    }
    $kernel->shutdown();
}
echo json_encode($results, JSON_THROW_ON_ERROR);
