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
    public function __construct(public string $resource, public string $identityKey) {}
}
