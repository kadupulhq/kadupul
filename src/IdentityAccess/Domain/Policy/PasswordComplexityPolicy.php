<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Domain\Policy;

use Kadupul\IdentityAccess\Domain\PasswordComplexity;

/**
 * Immutable configuration snapshot for local password complexity checks
 * (secpass_minlen, secpass_reqnum, secpass_reqmixcase, secpass_reqspec).
 */
final readonly class PasswordComplexityPolicy
{
    public function __construct(
        public bool $enforced = true,
        public int $minimumLength = 8,
        public bool $requireNumber = true,
        public bool $requireMixedCase = true,
        public bool $requireSpecial = true,
    ) {}

    /** @return list<string> Machine-readable violation codes, empty when satisfied. */
    public function violations(#[\SensitiveParameter] string $password, PasswordComplexity $checker): array
    {
        if (!$this->enforced) {
            return [];
        }

        $errors = [];
        if (!$checker->meetsMinimumLength($password, $this->minimumLength)) {
            $errors[] = 'min_length';
        }
        if ($this->requireNumber && !$checker->meetsNumberRequirement($password)) {
            $errors[] = 'require_number';
        }
        if ($this->requireMixedCase && !$checker->meetsCaseRequirement($password)) {
            $errors[] = 'require_mixed_case';
        }
        if ($this->requireSpecial && !$checker->meetsSpecialRequirement($password)) {
            $errors[] = 'require_special';
        }

        return $errors;
    }

    public function isSatisfiedBy(#[\SensitiveParameter] string $password, PasswordComplexity $checker): bool
    {
        return $this->violations($password, $checker) === [];
    }
}
