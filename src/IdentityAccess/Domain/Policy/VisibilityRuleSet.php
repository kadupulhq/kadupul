<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Domain\Policy;

use Kadupul\IdentityAccess\Domain\VisibilityDefault;

/**
 * Single resource-type rule combining a default allow/deny stance with explicit
 * exception ids from user_auth_perms or user_auth_group_perms.
 */
final readonly class VisibilityRuleSet
{
    /** @var array<int, true> */
    private array $exceptionIndex;

    /** @param list<int> $exceptions */
    public function __construct(
        public VisibilityDefault $default,
        array $exceptions = [],
    ) {
        $index = [];
        foreach ($exceptions as $id) {
            if ($id > 0) {
                $index[$id] = true;
            }
        }
        $this->exceptionIndex = $index;
    }

    public function allows(int $itemId): bool
    {
        if ($itemId < 1) {
            return false;
        }

        $isException = isset($this->exceptionIndex[$itemId]);

        return match ($this->default) {
            VisibilityDefault::Allow => !$isException,
            VisibilityDefault::Deny => $isException,
        };
    }

    public function hasExceptions(): bool
    {
        return $this->exceptionIndex !== [];
    }

    /** @return list<int> */
    public function exceptionIds(): array
    {
        $ids = array_keys($this->exceptionIndex);
        sort($ids, SORT_NUMERIC);

        return $ids;
    }
}
