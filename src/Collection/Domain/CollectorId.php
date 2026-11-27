<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Collection\Domain;

/**
 * Collector identifier matching poller.id (smallint unsigned, primary is 1).
 */
final readonly class CollectorId
{
    public const int PRIMARY_ID = 1;
    public const int MAX_ID = 65535;

    private int $value;

    private function __construct(int $value)
    {
        if ($value < 1 || $value > self::MAX_ID) {
            throw new \InvalidArgumentException(sprintf('Collector id must be between 1 and %d, got %d.', self::MAX_ID, $value));
        }

        $this->value = $value;
    }

    public static function primary(): self
    {
        return new self(self::PRIMARY_ID);
    }

    public static function fromInt(int $value): self
    {
        return new self($value);
    }

    public function toInt(): int
    {
        return $this->value;
    }

    public function isPrimary(): bool
    {
        return $this->value === self::PRIMARY_ID;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
