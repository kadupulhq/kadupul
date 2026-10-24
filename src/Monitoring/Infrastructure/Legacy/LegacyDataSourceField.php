<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Infrastructure\Legacy;

use Kadupul\Monitoring\Domain\RrdDataSourceType;

/** One field of a legacy data source, as data_template_rrd and poller_item describe it. */
final readonly class LegacyDataSourceField
{
    /**
     * @param string $name the stored data-source name, unvalidated
     * @param RrdDataSourceType|null $type null for COMPUTE and any type a binding cannot expect
     * @param bool $legacyWriter true when a poller_item row writes this field
     */
    public function __construct(
        public string $name,
        public int $typeId,
        public ?RrdDataSourceType $type,
        public bool $legacyWriter,
    ) {}
}
