<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

final class NativeCoverageStackTarget
{
    public static function run(int $value): int
    {
        if ($value === 7) return 42;
        throw new LogicException('Unexpected coverage probe input.');
    }
}

print NativeCoverageStackTarget::run(7);
define('NATIVE_COVERAGE_STACK_TARGET_DONE', true);
