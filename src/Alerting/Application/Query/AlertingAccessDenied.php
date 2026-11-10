<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Application\Query;

/** Raised before any alerting data is read; unauthenticated tells a missing sign-in from a missing realm. */
final class AlertingAccessDenied extends \RuntimeException
{
    public function __construct(public readonly bool $unauthenticated)
    {
        parent::__construct('Access denied.');
    }
}
