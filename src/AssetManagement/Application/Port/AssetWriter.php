<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\AssetManagement\Application\Port;

/** @phpstan-import-type Result from \Kadupul\AssetManagement\Domain\AssetData */
interface AssetWriter
{
    /**
     * @param array<string, mixed> $command
     * @phpstan-return Result
     */
    public function apply(array $command): array;
}
