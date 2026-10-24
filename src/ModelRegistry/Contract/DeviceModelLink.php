<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\ModelRegistry\Contract;

final class DeviceModelLink
{
    /**
     * @param string $state advertised, verified, partial, unsupported or unknown
     * @param list<string> $supportedPaths
     */
    public function __construct(
        public readonly int $deviceId,
        public readonly string $family,
        public readonly string $namespace,
        public readonly string $name,
        public readonly string $revision,
        public readonly string $state,
        public readonly array $supportedPaths,
    ) {}
}
