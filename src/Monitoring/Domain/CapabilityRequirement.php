<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Domain;

final readonly class CapabilityRequirement
{
    /**
     * @param list<string> $all every one must be present
     * @param list<string> $any at least one must be present when the list is not empty
     */
    public function __construct(public array $all, public array $any) {}

    /** @param list<string> $present */
    public function isSatisfiedBy(array $present): bool
    {
        return $this->unmet($present) === null;
    }

    /**
     * @param list<string> $present
     * @return string|null why the requirement is not met, or null when it is
     */
    public function unmet(array $present): ?string
    {
        $missing = array_values(array_diff($this->all, $present));
        if ($missing !== []) {
            return 'requires all of ' . implode(', ', $this->all) . '; missing ' . implode(', ', $missing);
        }
        if ($this->any !== [] && array_intersect($this->any, $present) === []) {
            return 'requires one of ' . implode(', ', $this->any);
        }

        return null;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_values(array_unique([...$this->all, ...$this->any]));
    }
}
