<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Infrastructure\Legacy;

/** A legacy data source whose identity cannot be trusted for binding without review. */
final readonly class IdentityConcern
{
    public const DUPLICATE_RESOURCE_NAME = 'duplicate_resource_name';
    public const REINDEXED_SNMP_INDEX = 'reindexed_snmp_index';
    public const NO_POLLER_ITEM = 'no_poller_item';
    public const MULTIPLE_FIELDS_PER_RRD = 'multiple_fields_per_rrd';

    public function __construct(
        public int $localDataId,
        public string $kind,
        public string $detail,
    ) {}
}
