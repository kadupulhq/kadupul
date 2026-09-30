<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

interface PaletteColorPreferences
{
    /** @return array<string, int|string>|null */
    public function load(): ?array;

    /** @param array<string, int|string> $filters */
    public function save(array $filters): void;
}
