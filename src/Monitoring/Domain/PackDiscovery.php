<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Domain;

/** Which resources a pack measures and which label identifies one of them. */
final readonly class PackDiscovery
{
    /**
     * Names the resource kind and its identity label.
     *
     * @param string $resource resource kind the pack measures, such as interface
     * @param string $identityKey label that identifies one resource of that kind, such as name
     */
    public function __construct(public string $resource, public string $identityKey) {}
}
