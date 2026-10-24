<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Domain;

/**
 * A model as a device names it. Kept as plain strings because ModelRegistry is
 * reachable only through its Contract; the registry decides whether it knows it.
 */
final readonly class ModelReference
{
    public const FAMILIES = ['yang', 'openconfig', 'redfish', 'snmp-mib'];

    public function __construct(
        public string $family,
        public string $namespace,
        public string $name,
        public string $revision,
    ) {
        if (!in_array($family, self::FAMILIES, true)) {
            throw new InvalidCapability(sprintf('Unknown model family "%s"; expected one of %s.', $family, implode(', ', self::FAMILIES)));
        }
        foreach (['namespace' => $namespace, 'name' => $name, 'revision' => $revision] as $field => $value) {
            // "|" and "@" separate the parts of key(), so they cannot appear inside one.
            if ($value === '' || preg_match('/[|@\x00-\x20\x7f]/', $value) === 1) {
                throw new InvalidCapability(sprintf('A model %s must be non-empty and contain no spaces, control characters, "|" or "@"; got "%s".', $field, $value));
            }
        }
    }

    /** Same "family|namespace|name" form ModelRegistry uses for dependencies. */
    public function identityKey(): string
    {
        return $this->family . '|' . $this->namespace . '|' . $this->name;
    }

    public function key(): string
    {
        return $this->identityKey() . '@' . $this->revision;
    }
}
