<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Symfony\Security;

use Kadupul\IdentityAccess\Application\Port\LoginRateLimitDecision;
use Kadupul\IdentityAccess\Application\Port\LoginRateLimiter;
use Psr\Log\LoggerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Additive login rate limiter backed by symfony/rate-limiter (ADR 0010 Decision 6).
 * Canonicalizes IPv4-mapped IPv6 addresses, checks the per-address bucket before
 * allocating any username bucket, and fails closed if limiter storage throws.
 */
final readonly class SymfonyLoginRateLimiter implements LoginRateLimiter
{
    private const int STORAGE_UNAVAILABLE_RETRY_SECONDS = 60;

    public function __construct(
        private RateLimiterFactoryInterface $addressLimiter,
        private RateLimiterFactoryInterface $accountLimiter,
        private ?LoggerInterface $logger = null,
    ) {}

    #[\Override]
    public function consume(string $clientAddress, string $username, int $accountRealm): LoginRateLimitDecision
    {
        $addressKey = 'addr:' . self::canonicalizeAddress($clientAddress);

        try {
            $addressLimit = $this->addressLimiter->create($addressKey)->consume(1);
            if (!$addressLimit->isAccepted()) {
                return LoginRateLimitDecision::deny(
                    LoginRateLimitDecision::BUCKET_ADDRESS,
                    self::retryAfterSeconds($addressLimit->getRetryAfter()),
                );
            }

            $accountKey = self::accountKey($username, $accountRealm);
            $accountLimit = $this->accountLimiter->create($accountKey)->consume(1);
            if (!$accountLimit->isAccepted()) {
                return LoginRateLimitDecision::deny(
                    LoginRateLimitDecision::BUCKET_ACCOUNT,
                    self::retryAfterSeconds($accountLimit->getRetryAfter()),
                );
            }

            return LoginRateLimitDecision::allow();
        } catch (\Throwable $error) {
            $this->logger?->error('Login rate limiter storage failure; failing closed.', [
                'exception' => $error::class,
            ]);

            return LoginRateLimitDecision::deny(
                LoginRateLimitDecision::BUCKET_STORAGE_UNAVAILABLE,
                self::STORAGE_UNAVAILABLE_RETRY_SECONDS,
            );
        }
    }

    #[\Override]
    public function resetAccount(string $username, int $accountRealm): void
    {
        try {
            $this->accountLimiter->create(self::accountKey($username, $accountRealm))->reset();
        } catch (\Throwable $error) {
            $this->logger?->warning('Login rate limiter account reset failed.', [
                'exception' => $error::class,
            ]);
        }
    }

    /**
     * Canonicalizes IPv4-mapped IPv6 addresses (for example ::ffff:192.0.2.1 -> 192.0.2.1)
     * and normalizes valid IPv6 text forms so equivalent addresses share one bucket.
     */
    public static function canonicalizeAddress(string $address): string
    {
        $trimmed = trim($address);
        if ($trimmed === '') {
            return 'unknown';
        }

        $packed = @inet_pton($trimmed);
        if ($packed === false) {
            return hash('sha256', $trimmed);
        }

        if (strlen($packed) === 16 && str_starts_with($packed, "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff")) {
            $ipv4 = inet_ntop(substr($packed, 12));
            if ($ipv4 !== false) {
                return $ipv4;
            }
        }

        $normalized = inet_ntop($packed);

        return $normalized !== false ? $normalized : hash('sha256', $trimmed);
    }

    private static function accountKey(string $username, int $accountRealm): string
    {
        return 'acct:' . $accountRealm . ':' . hash('sha256', strtolower($username));
    }

    private static function retryAfterSeconds(\DateTimeImmutable $retryAfter): int
    {
        return max(1, $retryAfter->getTimestamp() - time());
    }
}
