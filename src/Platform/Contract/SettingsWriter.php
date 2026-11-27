<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Contract;

/**
 * Cross-context contract for writing installation settings without direct
 * SQL mutations on the Platform-owned settings table.
 */
interface SettingsWriter
{
    public function write(string $name, string|int $value): void;

    public function delete(string $name): void;
}
