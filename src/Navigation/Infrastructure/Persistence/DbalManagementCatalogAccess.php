<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Navigation\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\Navigation\Application\Port\ManagementCatalogAccess;
use Kadupul\Navigation\Contract\Management\CatalogScreen;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\AuthenticatedAccess;
use Kadupul\Navigation\Application\Query\AdministrationListDenied;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsAlias(ManagementCatalogAccess::class)]
final readonly class DbalManagementCatalogAccess implements ManagementCatalogAccess
{
    private const int CONSOLE_REALM = 8;

    public function __construct(
        private ConsoleAccess $console,
        #[Autowire(service: 'doctrine.dbal.web_connection')]
        private Connection $database,
        private AuthenticatedAccess $identity,
    ) {}

    public function authorize(CatalogScreen $screen): Actor
    {
        $actor = $screen === CatalogScreen::MyReports ? $this->identity->authenticatedActor() : $this->console->consoleActor();
        if ($actor === null) {
            throw new AdministrationListDenied(true);
        }
        if (!$this->accountAllows($actor->id) || ($screen !== CatalogScreen::MyReports && !$this->hasRealm($actor->id, self::CONSOLE_REALM)) || !$this->hasRealm($actor->id, $screen->realm())) {
            throw new AdministrationListDenied(false);
        }
        return $actor;
    }

    private function accountAllows(int $actorId): bool
    {
        $user = $this->database->fetchAssociative('SELECT username, enabled, locked, must_change_password FROM user_auth WHERE id = ?', [$actorId]);
        if ($user === false || $user['enabled'] !== 'on' || $user['locked'] === 'on' || $user['must_change_password'] === 'on') {
            return false;
        }
        $method = $this->database->fetchOne("SELECT value FROM settings WHERE name = 'auth_method'");
        if ($method !== false && !in_array((int) $method, [1, 2, 3, 4], true)) {
            return false;
        }
        $guest = $this->database->fetchOne("SELECT value FROM settings WHERE name = 'guest_user'");
        return $actorId !== (int) $guest && $user['username'] !== $guest;
    }

    private function hasRealm(int $actorId, int $realmId): bool
    {
        if ($this->database->fetchOne('SELECT realm_id FROM user_auth_realm WHERE user_id = ? AND realm_id = ?', [$actorId, $realmId]) !== false) {
            return true;
        }
        return $this->database->fetchOne("SELECT r.realm_id FROM user_auth_group_realm r
            INNER JOIN user_auth_group_members m ON m.group_id = r.group_id
            INNER JOIN user_auth_group g ON g.id = r.group_id
            WHERE g.enabled = 'on' AND m.user_id = ? AND r.realm_id = ? LIMIT 1", [$actorId, $realmId]) !== false;
    }
}
