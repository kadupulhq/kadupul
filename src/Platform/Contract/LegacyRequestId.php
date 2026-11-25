<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Contract;

/** The ID a legacy page link carries, for the controllers old links land on. */
final class LegacyRequestId
{
    /**
     * An empty ID is 0, as the legacy pages read it. Anything else must be
     * plain decimal digits with no sign, padding or exponent.
     *
     * @param int<1, 18> $digits the most digits the ID column can hold
     * @throws \InvalidArgumentException carrying $error when the ID is malformed
     */
    public static function parse(mixed $raw, string $error, int $digits = 8): int
    {
        return match (true) {
            $raw === '' => 0,
            is_string($raw) && preg_match('/\A(?:0|[1-9][0-9]{0,' . ($digits - 1) . '})\z/D', $raw) === 1 => (int) $raw,
            default => throw new \InvalidArgumentException($error),
        };
    }
}
