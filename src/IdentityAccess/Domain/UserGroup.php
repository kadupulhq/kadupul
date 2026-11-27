<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Domain;

use Kadupul\IdentityAccess\Domain\Event\RealmGrantChanged;

/**
 * User group aggregate root modeling group status, realm grants, membership,
 * and default visibility policies (user_auth_group).
 */
final class UserGroup
{
    public private(set) string $name;

    /** @var list<int> */
    public private(set) array $realms;

    /** @var list<int> */
    public private(set) array $memberIds;

    /** @var list<RealmGrantChanged> */
    private array $events = [];

    /**
     * @param list<int> $realms
     * @param list<int> $memberIds
     */
    public function __construct(
        public readonly int $id,
        string $name,
        public private(set) string $description = '',
        public private(set) bool $enabled = true,
        public private(set) VisibilityDefault $policyGraphs = VisibilityDefault::Allow,
        public private(set) VisibilityDefault $policyTrees = VisibilityDefault::Allow,
        public private(set) VisibilityDefault $policyHosts = VisibilityDefault::Allow,
        public private(set) VisibilityDefault $policyGraphTemplates = VisibilityDefault::Allow,
        array $realms = [],
        array $memberIds = [],
    ) {
        if ($id < 1) {
            throw new \InvalidArgumentException('User group id must be positive.');
        }
        $trimmed = trim($name);
        if ($trimmed === '') {
            throw new \InvalidArgumentException('User group name must not be empty.');
        }

        $this->name = $trimmed;
        $this->realms = self::normalizePositiveIds($realms);
        $this->memberIds = self::normalizePositiveIds($memberIds);
    }

    public function hasEffectiveRealm(AuthRealm|int $realm): bool
    {
        if (!$this->enabled) {
            return false;
        }
        $realmId = $realm instanceof AuthRealm ? $realm->value : $realm;

        return in_array($realmId, $this->realms, true);
    }

    public function grantRealm(AuthRealm|int $realm, \DateTimeImmutable $occurredAt): void
    {
        $realmId = $realm instanceof AuthRealm ? $realm->value : $realm;
        if ($realmId < 1) {
            throw new \InvalidArgumentException('Realm id must be positive.');
        }
        if (in_array($realmId, $this->realms, true)) {
            return;
        }

        $realms = $this->realms;
        $realms[] = $realmId;
        sort($realms, SORT_NUMERIC);
        $this->realms = $realms;
        $this->events[] = new RealmGrantChanged($this->id, true, $realmId, true, $occurredAt);
    }

    public function revokeRealm(AuthRealm|int $realm, \DateTimeImmutable $occurredAt): void
    {
        $realmId = $realm instanceof AuthRealm ? $realm->value : $realm;
        if (!in_array($realmId, $this->realms, true)) {
            return;
        }

        $this->realms = array_values(array_filter(
            $this->realms,
            static fn(int $id): bool => $id !== $realmId,
        ));
        $this->events[] = new RealmGrantChanged($this->id, true, $realmId, false, $occurredAt);
    }

    public function addMember(int $userId): void
    {
        if ($userId < 1) {
            throw new \InvalidArgumentException('Member user id must be positive.');
        }
        if (in_array($userId, $this->memberIds, true)) {
            return;
        }

        $members = $this->memberIds;
        $members[] = $userId;
        sort($members, SORT_NUMERIC);
        $this->memberIds = $members;
    }

    public function removeMember(int $userId): void
    {
        $this->memberIds = array_values(array_filter(
            $this->memberIds,
            static fn(int $id): bool => $id !== $userId,
        ));
    }

    public function containsMember(int $userId): bool
    {
        return in_array($userId, $this->memberIds, true);
    }

    public function disable(): void
    {
        $this->enabled = false;
    }

    public function enable(): void
    {
        $this->enabled = true;
    }

    /** @return list<RealmGrantChanged> */
    public function releaseEvents(): array
    {
        $events = $this->events;
        $this->events = [];

        return $events;
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private static function normalizePositiveIds(array $ids): array
    {
        $unique = [];
        foreach ($ids as $id) {
            if ($id > 0) {
                $unique[$id] = $id;
            }
        }
        sort($unique, SORT_NUMERIC);

        return $unique;
    }
}
