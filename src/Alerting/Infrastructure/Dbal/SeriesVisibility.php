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
 * class only places it on the data_local alias `dl`. Every predicate is
 * evaluated by the query that reads the rule, on the alerting connection.
 */
final readonly class SeriesVisibility
{
    public function __construct(private ManagementVisibility $visibility) {}

    /** A data source the actor can view. */
    public function visible(int $actorId): string
    {
        $devices = $this->visibility->forActor($actorId)['devices'];

        return "(dl.host_id = 0 OR dl.host_id IN ($devices))";
    }

    /**
     * A rule the actor may see: its data source is visible, or it no longer
     * exists, so a rule whose data source was deleted can still be deleted.
     */
    public function ruleVisible(int $actorId): string
    {
        return '(dl.id IS NULL OR ' . $this->visible($actorId) . ')';
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
