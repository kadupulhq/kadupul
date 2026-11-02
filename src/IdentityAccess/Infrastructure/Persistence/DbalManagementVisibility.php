<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\IdentityAccess\Contract\VisibilitySql;
use Kadupul\IdentityAccess\Contract\ManagementVisibility;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Current management predicates, read on the same connection as the catalog. */
#[AsAlias(ManagementVisibility::class)]
final readonly class DbalManagementVisibility implements ManagementVisibility
{
    public function __construct(#[Autowire(service: 'doctrine.dbal.web_connection')] private Connection $database) {}

    /** @return array{graphs: string, devices: string} */
    public function forActor(int $actorId): array
    {
        if ($actorId < 1) {
            throw new \InvalidArgumentException('Invalid management actor.');
        }
        $policies = $this->database->fetchAllAssociative(VisibilitySql::POLICIES, [$actorId, $actorId]);
        $user = array_find($policies, static fn(array $policy): bool => $policy['kind'] === 'user');
        if ($user === null) {
            throw new \RuntimeException('Management actor is unavailable.');
        }
        $exceptions = $this->database->fetchAllKeyValue('SELECT type, COUNT(*) FROM user_auth_perms WHERE user_id = ? AND type IN (1, 3) GROUP BY type', [$actorId]);
        $simpleGraphs = self::integer($user['policy_graphs']) === 1 && self::integer($exceptions[1] ?? 0) === 0;
        $simpleDevices = self::integer($user['policy_hosts']) === 1 && self::integer($exceptions[3] ?? 0) === 0;
        $mode = $simpleGraphs && $simpleDevices ? 1 : VisibilitySql::mode($this->database->fetchOne(VisibilitySql::MODE));
        $policy = $simpleGraphs && $simpleDevices ? '1 = 1' : VisibilitySql::predicate($mode, $policies);
        return [
            'graphs' => "SELECT DISTINCT gl.id FROM graph_local gl INNER JOIN graph_templates_graph gtg ON gtg.local_graph_id = gl.id
                LEFT JOIN host h ON h.id = gl.host_id WHERE " . ($simpleGraphs ? '1 = 1' : "($policy)"),
            'devices' => "SELECT DISTINCT h.id FROM host h LEFT JOIN graph_local gl ON gl.host_id = h.id WHERE h.id > 0 AND " . ($simpleDevices ? '1 = 1' : "($policy)"),
        ];
    }

    private static function integer(mixed $value): int
    {
        if (!is_int($value) && !is_string($value)) {
            throw new \UnexpectedValueException('Invalid management policy counter.');
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false) {
            throw new \UnexpectedValueException('Invalid management policy counter.');
        }
        return $integer;
    }
}
