<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Collection\Domain;

/**
 * Validated polling interval in seconds. Standard intervals match
 * $poller_intervals in include/global_arrays.php.
 */
final readonly class PollingInterval
{
    public const int DEFAULT_SECONDS = 300;
    public const int MIN_SECONDS = 1;
    public const int MAX_SECONDS = 86400;

    /** @var list<int> */
    public const array STANDARD_SECONDS = [10, 15, 20, 30, 60, 300];

    private int $seconds;

    private function __construct(int $seconds)
    {
        if ($seconds < self::MIN_SECONDS || $seconds > self::MAX_SECONDS) {
            throw new \InvalidArgumentException(sprintf('Polling interval must be between %d and %d seconds, got %d.', self::MIN_SECONDS, self::MAX_SECONDS, $seconds));
        }

        $this->seconds = $seconds;
    }

    public static function fromSeconds(int $seconds): self
    {
        return new self($seconds);
    }

    public static function default(): self
    {
        return new self(self::DEFAULT_SECONDS);
    }

    public function seconds(): int
    {
        return $this->seconds;
    }

    public function isStandard(): bool
    {
        return in_array($this->seconds, self::STANDARD_SECONDS, true);
    }

    public function equals(self $other): bool
    {
        return $this->seconds === $other->seconds;
    }
}
