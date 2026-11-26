<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Symfony\Validation;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Form/HTTP submission DTO validated at the Symfony boundary before invoking
 * ChangePassword. Domain complexity rules remain in PasswordComplexity.
 */
#[Exclude]
final readonly class PasswordChangeInput
{
    public function __construct(
        #[Assert\Length(max: 4096)]
        #[\SensitiveParameter]
        public string $currentPassword,
        #[Assert\NotBlank(message: 'New password is required.')]
        #[Assert\Length(min: 1, max: 4096)]
        #[\SensitiveParameter]
        public string $newPassword,
        #[Assert\NotBlank(message: 'Password confirmation is required.')]
        #[Assert\EqualTo(propertyPath: 'newPassword', message: 'Password confirmation does not match.')]
        #[\SensitiveParameter]
        public string $confirmPassword,
    ) {}
}
