<?php

declare(strict_types=1);

/* SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Domain\Installer;

final readonly class OperationalVerificationRequest
{
    /** @param list<int> $graphIds */
    private function __construct(public array $graphIds) {}

    /** @param array<array-key, mixed> $values */
    public static function fromGraphIds(array $values): self
    {
        if (!array_is_list($values) || count($values) > 20) {
            throw new \InvalidArgumentException('Supply at most 20 graph IDs.');
        }
        $ids = [];
        foreach ($values as $value) {
            if ((!is_string($value) && !is_int($value)) || preg_match('/\A[1-9][0-9]{0,9}\z/D', (string) $value) !== 1) {
                throw new \InvalidArgumentException('Graph IDs must be positive integers.');
            }
            $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
            if (!is_int($id)) {
                throw new \InvalidArgumentException('Graph ID is outside the supported range.');
            }
            $ids[$id] = $id;
        }
        return new self(array_values($ids));
    }
}
