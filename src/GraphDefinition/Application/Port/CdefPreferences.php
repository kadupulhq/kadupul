<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\GraphDefinition\Application\Port;

interface CdefPreferences
{
    /** @return array<string, string>|null */
    public function load(): ?array;

    /** @param array<string, string> $filters */
    public function save(array $filters): void;
}
