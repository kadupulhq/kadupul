<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Port;

/**
 * Decision from the additive login rate limiter (ADR 0010 Decision 6).
 * A refusal maps to HTTP 429 and never triggers account lockout.
 */
final readonly class LoginRateLimitDecision
{
    public const string BUCKET_ADDRESS = 'address';
    public const string BUCKET_ACCOUNT = 'account';
    public const string BUCKET_STORAGE_UNAVAILABLE = 'storage_unavailable';

    private function __construct(
        public bool $allowed,
        public int $retryAfterSeconds,
        public ?string $exhaustedBucket,
    ) {}

    public static function allow(): self
    {
        return new self(true, 0, null);
    }

    public static function deny(string $bucket, int $retryAfterSeconds): self
    {
        return new self(false, max(1, $retryAfterSeconds), $bucket);
    }
}
