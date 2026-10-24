<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\ModelRegistry\Contract;

final class ModelSummary
{
    /**
     * @param list<string> $capabilities
     * @param list<string> $dependencies "family|namespace|name@minimum" or "family|namespace|name" when no minimum is set
     */
    public function __construct(
        public readonly string $family,
        public readonly string $namespace,
        public readonly string $name,
        public readonly string $revision,
        public readonly array $capabilities,
        public readonly array $dependencies,
        public readonly string $checksum,
    ) {}
}
