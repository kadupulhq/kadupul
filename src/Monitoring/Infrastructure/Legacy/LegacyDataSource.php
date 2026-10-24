<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Infrastructure\Legacy;

/** A device's legacy data source (one RRD file) and what is known about its identity. */
final readonly class LegacyDataSource
{
    /**
     * @param int $dataQueryId 0 when the data source does not come from a data query
     * @param string $protocol snmp for the indexed SNMP data input, otherwise legacy
     * @param array<string, string> $resourceHints present host_snmp_cache values for the index, by field name
     * @param list<LegacyDataSourceField> $fields
     * @param string $pathPlaceholder data_source_path as stored, normally <path_rra>/...; never resolved here
     * @param list<int> $graphIds local graphs that draw any of the fields
     */
    public function __construct(
        public int $deviceId,
        public int $localDataId,
        public int $dataQueryId,
        public string $index,
        public string $protocol,
        public array $resourceHints,
        public array $fields,
        public string $pathPlaceholder,
        public array $graphIds,
    ) {}
}
