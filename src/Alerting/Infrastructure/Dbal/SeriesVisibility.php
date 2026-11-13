<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Infrastructure\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Kadupul\IdentityAccess\Contract\ManagementVisibility;

/**
 * The data-source visibility policy of the data-source views, as
 * get_allowed_management_selection('data', ...) in lib/auth.php applies it: a
 * data source without a device (host_id 0) is visible, and one with a device
 * is visible when the actor may see that device. The device policy comes
 * from ManagementVisibility, the shared projection of the legacy rules; this
 * class only places it on a data_local alias. Every predicate is evaluated
 * by the query that reads the rule or incident, on the alerting connection.
 */
final readonly class SeriesVisibility
{
    public function __construct(private ManagementVisibility $visibility) {}

    /** A data source, joined as $alias, that the actor can view. */
    public function visible(int $actorId, string $alias = 'dl'): string
    {
        $devices = $this->visibility->forActor($actorId)['devices'];

        return "($alias.host_id = 0 OR $alias.host_id IN ($devices))";
    }

    /**
     * A series the actor may see, its data source joined as $alias: one that
     * exists and is visible, or one whose data source is gone when the actor
     * has full device access. A deleted data source no longer names its
     * device, so its visibility cannot be decided and it fails closed. Rules
     * and incidents both use this; an incident passes its recorded series.
     */
    public function seriesVisible(int $actorId, string $alias = 'dl'): string
    {
        return "(($alias.id IS NOT NULL AND " . $this->visible($actorId, $alias) . ") OR ($alias.id IS NULL AND " . self::allDevices($actorId) . '))';
    }

    /** A rule, its data source joined as dl, whose series the actor may see. */
    public function ruleVisible(int $actorId): string
    {
        return $this->seriesVisible($actorId, 'dl');
    }

    /**
     * The actor has full device access, as get_simple_device_perms() in
     * lib/auth.php and DbalManagementVisibility's simple device policy
     * decide it: the account's device policy allows (policy_hosts 1) and it
     * has no device exceptions (user_auth_perms type 3). The legacy group
     * branch joins only groups that have exceptions, so it never grants
     * full access and is not repeated. $actorId is an int.
     */
    public static function allDevices(int $actorId): string
    {
        return "(EXISTS (SELECT 1 FROM user_auth uav WHERE uav.id = $actorId AND uav.policy_hosts = 1)
            AND NOT EXISTS (SELECT 1 FROM user_auth_perms uapv WHERE uapv.user_id = $actorId AND uapv.type = 3))";
    }

    /**
     * A bound RRD data source name compared byte for byte, as alert_rule's
     * varbinary rrd_name compares with data_template_rrd.data_source_name in
     * the evaluator. SQLite compares bytes by default.
     */
    public static function bytes(Connection $database): string
    {
        return $database->getDatabasePlatform() instanceof SQLitePlatform ? '?' : 'CAST(? AS BINARY)';
    }
}
