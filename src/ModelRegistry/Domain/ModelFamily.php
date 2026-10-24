<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\ModelRegistry\Domain;

enum ModelFamily: string
{
    case Yang = 'yang';
    case OpenConfig = 'openconfig';
    case Redfish = 'redfish';
    case SnmpMib = 'snmp-mib';

    public static function fromString(string $value): self
    {
        return self::tryFrom($value) ?? throw new InvalidModelDefinition(sprintf(
            'Unknown model family "%s"; expected one of %s.',
            $value,
            implode(', ', array_map(static fn(self $f): string => $f->value, self::cases())),
        ));
    }

    /** Date-stamped revisions in the YANG convention, as opposed to free-form version strings. */
    public function usesDateRevisions(): bool
    {
        return $this === self::Yang || $this === self::OpenConfig;
    }
}
