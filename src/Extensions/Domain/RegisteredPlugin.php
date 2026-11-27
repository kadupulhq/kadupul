<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Extensions\Domain;

final readonly class RegisteredPlugin
{
    public function __construct(
        public int $id,
        public PluginMetadata $metadata,
        public PluginStatus $status,
    ) {
        if ($this->id < 1) {
            throw new \InvalidArgumentException('Registered plugin id must be positive.');
        }
    }
}
