<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Automation\Domain;

/** The AVAIL_* constants from include/global_constants.php, in the legacy menu order. */
final class AvailabilityMethods
{
    public const int SNMP = 2;

    public const array LABELS = [
        0 => 'None',
        1 => 'Ping and SNMP Uptime',
        4 => 'Ping or SNMP Uptime',
        2 => 'SNMP Uptime',
        5 => 'SNMP Desc',
        6 => 'SNMP getNext',
        3 => 'Ping',
    ];
}
