<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Domain;

/**
 * A field name inside an RRD file. RRDtool accepts 1 to 19 characters of
 * [A-Za-z0-9_-] (DS_NAM_FMT in rrd_format.h); anything else would fail at
 * update time, so it is refused here instead.
 */
final readonly class RrdDataSourceName
{
    public function __construct(public string $value)
    {
        if (preg_match('/\A[A-Za-z0-9_-]{1,19}\z/', $value) !== 1) {
            throw new InvalidBinding(sprintf('Data-source name %s must be 1 to 19 characters of A-Z, a-z, 0-9, underscore or hyphen.', json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE)));
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
