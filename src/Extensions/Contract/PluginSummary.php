<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Extensions\Contract;

final readonly class PluginSummary
{
    public function __construct(
        public int $id,
        public string $slug,
        public string $name,
        public string $version,
        public string $author,
        public ?string $webpage,
        public string $status,
        public bool $enabled,
    ) {}
}
