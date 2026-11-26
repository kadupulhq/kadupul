<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Command;

use Kadupul\IdentityAccess\Domain\CredentialGeneration;
use Kadupul\IdentityAccess\Domain\Event\CredentialsRevoked;

/**
 * Immutable outcome of a local password change operation.
 */
final readonly class PasswordChangeResult
{
    public const string STATUS_CHANGED = 'changed';
    public const string STATUS_ACCOUNT_INELIGIBLE = 'account_ineligible';
    public const string STATUS_NOT_ALLOWED = 'not_allowed';
    public const string STATUS_CURRENT_PASSWORD_INVALID = 'current_password_invalid';
    public const string STATUS_COMPLEXITY_VIOLATION = 'complexity_violation';
    public const string STATUS_PASSWORD_REUSED = 'password_reused';
    public const string STATUS_CONFLICT = 'conflict';

    /** @param list<string> $complexityViolations */
    private function __construct(
        public string $status,
        public array $complexityViolations = [],
        public ?CredentialGeneration $newGeneration = null,
        public ?CredentialsRevoked $event = null,
    ) {}

    public static function changed(CredentialGeneration $newGeneration, CredentialsRevoked $event): self
    {
        return new self(self::STATUS_CHANGED, [], $newGeneration, $event);
    }

    public static function accountIneligible(): self
    {
        return new self(self::STATUS_ACCOUNT_INELIGIBLE);
    }

    public static function notAllowed(): self
    {
        return new self(self::STATUS_NOT_ALLOWED);
    }

    public static function currentPasswordInvalid(): self
    {
        return new self(self::STATUS_CURRENT_PASSWORD_INVALID);
    }

    /** @param list<string> $violations */
    public static function complexityViolation(array $violations): self
    {
        return new self(self::STATUS_COMPLEXITY_VIOLATION, $violations);
    }

    public static function passwordReused(): self
    {
        return new self(self::STATUS_PASSWORD_REUSED);
    }

    public static function conflict(): self
    {
        return new self(self::STATUS_CONFLICT);
    }

    public function isChanged(): bool
    {
        return $this->status === self::STATUS_CHANGED;
    }
}
