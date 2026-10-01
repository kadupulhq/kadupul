<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\AggregateTemplate\Domain;

final class AggregateTemplateItemPolicy
{
    public static function forceSkip(int $graphType, string $value, string $text): bool
    {
        return match ($graphType) {
            2 => preg_match('/(:bits:|:bytes:|\|sum:)/', $value) !== 1,
            3, 30, 40 => true,
            1 => $text === '',
            default => false,
        };
    }
}
