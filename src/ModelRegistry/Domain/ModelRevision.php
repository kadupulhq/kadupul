<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\ModelRegistry\Domain;

final class ModelRevision
{
    private const FREE_FORM_PATTERN = '/\A[0-9A-Za-z][0-9A-Za-z._+-]{0,63}\z/';

    private function __construct(private readonly ModelFamily $family, private readonly string $value) {}

    public static function parse(ModelFamily $family, string $value): self
    {
        if ($family->usesDateRevisions()) {
            if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $value, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                throw new InvalidModelDefinition(sprintf('Invalid %s revision "%s"; expected a calendar date as YYYY-MM-DD.', $family->value, $value));
            }
        } elseif (preg_match(self::FREE_FORM_PATTERN, $value) !== 1) {
            throw new InvalidModelDefinition(sprintf('Invalid %s revision "%s"; expected letters, digits and . _ + - only.', $family->value, $value));
        }

        return new self($family, $value);
    }

    public function family(): ModelFamily
    {
        return $this->family;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function compareTo(self $other): int
    {
        if ($this->family !== $other->family) {
            throw new InvalidModelDefinition(sprintf('Cannot compare %s revision %s with %s revision %s.', $this->family->value, $this->value, $other->family->value, $other->value));
        }

        // ISO dates order correctly as strings. Free-form revisions use version
        // ordering so that 1.10.0 follows 1.9.0.
        return $this->family->usesDateRevisions()
            ? $this->value <=> $other->value
            : version_compare($this->value, $other->value);
    }

    public function equals(self $other): bool
    {
        return $this->family === $other->family && $this->value === $other->value;
    }
}
