<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Domain;

/** How one protocol yields one pack metric. */
final readonly class PackMapping
{
    /**
     * @param string $path SNMP OID, gNMI path, Redfish resource path, NETCONF XPath or RESTCONF resource path
     * @param string|null $property Redfish property within the resource; null for every other protocol
     * @param array<string, int>|null $valueMap
     */
    public function __construct(
        public string $protocol,
        public string $path,
        public ?string $property = null,
        public int|float|null $scale = null,
        public ?array $valueMap = null,
    ) {}

    /**
     * What a plan's metric source names. A Redfish resource carries many
     * properties, so the property is appended as a JSON pointer fragment.
     */
    public function source(): string
    {
        return $this->property === null ? $this->path : $this->path . '#/' . str_replace(['~', '/'], ['~0', '~1'], $this->property);
    }
}
