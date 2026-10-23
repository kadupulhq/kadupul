<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Monitoring\Fakes;

/** MIB support as sysORTable or a walk of the MIB's tables would show it. */
final class FakeSnmpCapabilityProvider extends FakeCapabilityProvider
{
    public function protocol(): string
    {
        return 'snmp';
    }

    protected function family(): string
    {
        return 'snmp-mib';
    }
}
