<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Application\ReadModel;

/**
 * The series an incident opened on, named as RuleView names a rule's series:
 * the data source id, the RRD data source name and the device description
 * when the data source has a device. $exists is false once the data source,
 * its device or its RRD data source is gone.
 */
final readonly class SeriesView
{
    public function __construct(
        public int $localDataId,
        public string $rrdName,
        public bool $seriesExists,
        public ?string $device,
    ) {}
}
