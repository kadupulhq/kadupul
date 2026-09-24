<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Infrastructure\Legacy\LegacySiteEditor;
use Kadupul\Kernel;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Infrastructure\Legacy\InstallationConfiguration;
use Kadupul\Platform\Infrastructure\Legacy\InstallationDatabase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
// The behavioral image deliberately excludes tests; the controller executes
// this probe through php -r from the installed application's root.
require getcwd() . '/include/vendor/autoload.php';
$input = json_decode($argv[1], true, 32, JSON_THROW_ON_ERROR);
$userId = (int) $input['user'];
$siteId = (int) $input['site'];
$_COOKIE['Cacti'] = $input['cookie'];
$audit = new \Kadupul\Inventory\Infrastructure\Legacy\SiteWriteAudit(new \Kadupul\IdentityAccess\Infrastructure\Legacy\LegacyAuditTrail(getcwd()));
$kernel = new Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$container->get(RequestStack::class)->push(Request::create('/inventory/sites/' . $siteId, 'GET', [], $_COOKIE));
$database = $container->get(DatabaseConnection::class);
$access = $container->get(ConsoleAccess::class);
$rival = (new InstallationDatabase(new InstallationConfiguration(getcwd())))->get();
$rival->exec('SET SESSION innodb_lock_wait_timeout = 1');
$results = [];
$groupId = null;
try {
    if ($input['mode'] === 'revoke') {
        $revoking = new class ($access, $database, $rival, $userId) implements ConsoleAccess {
            public function __construct(private ConsoleAccess $delegate, private DatabaseConnection $database, private PDO $rival, private int $userId) {}
            public function consoleActor(): ?Actor
            {
                // Establish an old consistent snapshot, then commit revocation
                // from another connection before the real persistence recheck.
                $this->database->get()->query('SELECT enabled FROM user_auth WHERE id = ' . $this->userId)->fetchColumn();
                $this->rival->exec("UPDATE user_auth SET enabled = '' WHERE id = " . $this->userId);
                return $this->delegate->consoleActor();
            }
            public function canManageDevices(Actor $actor): bool
            {
                return $this->delegate->canManageDevices($actor);
            }
        };
        $editor = new LegacySiteEditor($database, $revoking, $audit);
        $site = $editor->find($siteId);
        $revision = $site->revision();
        $site->revise('Must not be saved', '', $revision);
        try {
            $editor->save($userId, $site, $revision);
            $results['denied'] = false;
        } catch (InventoryAccessDenied $error) {
            $results['denied'] = $error->unauthenticated;
        } finally {
            $rival->exec("UPDATE user_auth SET enabled = 'on' WHERE id = $userId");
        }
        $results['rolled_back'] = !$database->get()->inTransaction();
        $results['unchanged'] = $editor->find($siteId)->revision() === $revision;
        $results['revocation_survives'] = $access->consoleActor() === null;
    } else {
        $guard = new class ($access, $rival) implements ConsoleAccess {
            public string $mutation;
            public bool $blocked = false;
            public function __construct(private ConsoleAccess $delegate, private PDO $rival) {}
            public function consoleActor(): ?Actor
            {
                return $this->delegate->consoleActor();
            }
            public function canManageDevices(Actor $actor): bool
            {
                $allowed = $this->delegate->canManageDevices($actor);
                // A second connection attempts revocation after authorization,
                // before the site UPDATE. Never commit a test mutation.
                $this->rival->beginTransaction();
                try {
                    $this->rival->exec($this->mutation);
                } catch (PDOException $error) {
                    if (($error->errorInfo[1] ?? null) !== 1205) {
                        throw $error;
                    }
                    $this->blocked = true;
                } finally {
                    $this->rival->rollBack();
                }
                return $allowed;
            }
        };
        $editor = new LegacySiteEditor($database, $guard, $audit);
        $mutations = [
            'account' => "UPDATE user_auth SET enabled = '' WHERE id = $userId",
            'console' => "DELETE FROM user_auth_realm WHERE user_id = $userId AND realm_id = 8",
            'devices' => "DELETE FROM user_auth_realm WHERE user_id = $userId AND realm_id = 3",
            'auth_method' => "UPDATE settings SET value = '0' WHERE name = 'auth_method'",
            'guest' => "UPDATE settings SET value = 'admin' WHERE name = 'guest_user'",
        ];
        $rival->exec("INSERT INTO user_auth_group (name, enabled) VALUES ('site-lock-fixture', 'on')");
        $groupId = (int) $rival->lastInsertId();
        $rival->exec("INSERT INTO user_auth_group_members (group_id, user_id) VALUES ($groupId, $userId)");
        $rival->exec("INSERT INTO user_auth_group_realm (group_id, realm_id) VALUES ($groupId, 3)");
        $mutations += [
            'membership' => "DELETE FROM user_auth_group_members WHERE group_id = $groupId AND user_id = $userId",
            'group_enabled' => "UPDATE user_auth_group SET enabled = '' WHERE id = $groupId",
            'group_realm' => "DELETE FROM user_auth_group_realm WHERE group_id = $groupId AND realm_id = 3",
        ];
        foreach ($mutations as $case => $mutation) {
            if ($case === 'membership') {
                $rival->exec("DELETE FROM user_auth_realm WHERE user_id = $userId AND realm_id = 3");
            }
            $guard->mutation = $mutation;
            $guard->blocked = false;
            $site = $editor->find($siteId);
            $revision = $site->revision();
            $site->revise('Locked authorization ' . $case, '', $revision);
            $editor->save($userId, $site, $revision);
            $results[$case] = $guard->blocked && $editor->find($siteId)->name() === $site->name();
            // The same revocation must work once the site transaction ends.
            $rival->beginTransaction();
            $rival->exec($mutation);
            $results[$case . '_released'] = $rival->query('SELECT ROW_COUNT()')->fetchColumn() > 0;
            $rival->rollBack();
        }
    }
} finally {
    if ($database->get()->inTransaction()) {
        $database->get()->rollBack();
    }
    if ($rival->inTransaction()) {
        $rival->rollBack();
    }
    if ($groupId !== null) {
        $rival->exec("REPLACE INTO user_auth_realm (user_id, realm_id) VALUES ($userId, 3)");
        $rival->exec("DELETE FROM user_auth_group_realm WHERE group_id = $groupId");
        $rival->exec("DELETE FROM user_auth_group_members WHERE group_id = $groupId");
        $rival->exec("DELETE FROM user_auth_group WHERE id = $groupId");
    }
    $kernel->shutdown();
}
echo json_encode($results, JSON_THROW_ON_ERROR);
