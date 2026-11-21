<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Domain\Rule;

/** One dsstats series: a data source and its RRD data source name (rrd_name, varchar(19)). */
final readonly class SeriesKey
{
    public function __construct(
        public int $localDataId,
        public string $rrdName,
    ) {
        if ($localDataId < 1) {
            throw new InvalidRule('A series needs a data source id of at least 1.');
        }
        if ($rrdName === '' || strlen($rrdName) > 19) {
            throw new InvalidRule('A series needs a data source name of 1 to 19 bytes.');
        }
    }

    /** A stable array key; the id has no colon, so the first colon separates the parts. */
    public function id(): string
    {
        return $this->localDataId . ':' . $this->rrdName;
    }
}
