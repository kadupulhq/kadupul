<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Telemetry\Contract;

/**
 * The protocol a sample was collected over. The values are the protocol
 * names a collection plan target uses, so a collector can carry its plan's
 * protocol onto every sample without translation.
 */
enum CollectionProtocol: string
{
    case Snmp = 'snmp';
    case Gnmi = 'gnmi';
    case Redfish = 'redfish';
    case Netconf = 'netconf';
    case Restconf = 'restconf';

    public static function fromName(string $name): self
    {
        return self::tryFrom($name) ?? throw new InvalidTelemetryValue(sprintf(
            'Unknown collection protocol "%s"; expected one of %s.',
            $name,
            implode(', ', array_map(static fn(self $p): string => $p->value, self::cases())),
        ));
    }
}
