<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Contract;

/**
 * Cross-context contract for reading and mutating per-user preferences stored
 * in Platform's settings_user table.
 */
interface UserPreferenceStore
{
    public function get(int $userId, string $name): ?string;

    public function set(int $userId, string $name, string|int $value): void;

    public function remove(int $userId, string $name): void;
}
