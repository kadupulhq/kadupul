<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

/**
 * One row the script selected: a data source of a data query and the output
 * type stored for it. The two nullable fields are what the script's left joins
 * left empty; they are passed on as read.
 */
final readonly class DataQueryIndexItem
{
    public function __construct(
        public int $hostId,
        public int $snmpQueryId,
        public string $snmpIndex,
        public ?int $dataTemplateDataId,
        public ?string $outputType,
    ) {}
}
